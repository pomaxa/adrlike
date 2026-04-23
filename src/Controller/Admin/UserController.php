<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\User;
use App\Form\PasswordResetType;
use App\Form\PromotePlaceholderType;
use App\Form\UserCreateType;
use App\Form\UserEditType;
use App\Repository\UserRepository;
use App\Service\SsoStatusProvider;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_ADMIN')]
#[Route('/admin/users')]
final class UserController extends AbstractController
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly EntityManagerInterface $em,
        private readonly UserPasswordHasherInterface $hasher,
        private readonly LoggerInterface $logger,
        private readonly SsoStatusProvider $sso,
    ) {
    }

    #[Route('', name: 'app_admin_user_index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $filters = [
            'search' => $request->query->get('q'),
            'role' => $request->query->get('role'),
            'placeholder' => match ($request->query->get('placeholder')) {
                'yes' => true,
                'no' => false,
                default => null,
            },
        ];

        $users = $this->users->queryForAdminList($filters)
            ->setMaxResults(500)
            ->getQuery()
            ->getResult();

        return $this->render('admin/user/index.html.twig', [
            'users' => $users,
            'filters' => $filters,
            'sso' => [
                'status' => $this->sso->statusCode(),
                'tenant_suffix' => $this->sso->tenantSuffix(),
            ],
        ]);
    }

    #[Route('/new', name: 'app_admin_user_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $user = new User('', '');
        $form = $this->createForm(UserCreateType::class, $user);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $plain = (string) $form->get('password')->getData();
            $user->setPassword($this->hasher->hashPassword($user, $plain));
            $user->setRoles($form->get('roles')->getData());
            $user->setPlaceholder(false);

            $this->em->persist($user);
            $this->em->flush();

            $this->addFlash('success', sprintf('Created user %s.', $user->getEmail()));
            return $this->redirectToRoute('app_admin_user_index');
        }

        $status = $form->isSubmitted() ? 422 : 200;
        return $this->render('admin/user/new.html.twig', ['form' => $form->createView()], new Response(null, $status));
    }

    #[Route('/{id}', name: 'app_admin_user_show', methods: ['GET'], requirements: ['id' => '[0-9a-f-]{36}'])]
    public function show(string $id): Response
    {
        $user = $this->users->find($id) ?? throw $this->createNotFoundException();
        return $this->render('admin/user/show.html.twig', [
            'user' => $user,
            'decisionCount' => $this->users->countDecisionReferences($user),
        ]);
    }

    #[Route('/{id}/edit', name: 'app_admin_user_edit', methods: ['GET', 'POST'], requirements: ['id' => '[0-9a-f-]{36}'])]
    public function edit(Request $request, string $id): Response
    {
        $user = $this->users->find($id) ?? throw $this->createNotFoundException();
        $isSelf = $user === $this->getUser();

        $form = $this->createForm(UserEditType::class, $user, [
            'is_self' => $isSelf,
            'current_roles' => $user->getRoles(),
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            if (!$isSelf) {
                $user->setRoles($form->get('roles')->getData());
            }
            $this->em->flush();
            $this->addFlash('success', 'User updated.');
            return $this->redirectToRoute('app_admin_user_show', ['id' => $user->getId()]);
        }

        $status = $form->isSubmitted() ? 422 : 200;
        return $this->render('admin/user/edit.html.twig', [
            'user' => $user,
            'form' => $form->createView(),
            'isSelf' => $isSelf,
        ], new Response(null, $status));
    }

    #[Route('/{id}/password', name: 'app_admin_user_password', methods: ['GET', 'POST'], requirements: ['id' => '[0-9a-f-]{36}'])]
    public function resetPassword(Request $request, string $id): Response
    {
        $user = $this->users->find($id) ?? throw $this->createNotFoundException();

        if ($user->isPlaceholder()) {
            $this->addFlash('warning', 'Placeholder users must be promoted before a password can be set.');
            return $this->redirectToRoute('app_admin_user_promote', ['id' => $user->getId()]);
        }

        $form = $this->createForm(PasswordResetType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $plain = (string) $form->get('password')->getData();
            $user->setPassword($this->hasher->hashPassword($user, $plain));
            $this->em->flush();

            $actor = $this->getUser();
            $this->logger->info('Admin password reset', [
                'target_email' => $user->getEmail(),
                'actor_email' => $actor?->getUserIdentifier(),
            ]);

            $this->addFlash('success', sprintf('Password reset for %s.', $user->getEmail()));
            return $this->redirectToRoute('app_admin_user_show', ['id' => $user->getId()]);
        }

        $status = $form->isSubmitted() ? 422 : 200;
        return $this->render('admin/user/password.html.twig', [
            'user' => $user,
            'form' => $form->createView(),
        ], new Response(null, $status));
    }

    #[Route('/{id}/promote', name: 'app_admin_user_promote', methods: ['GET', 'POST'], requirements: ['id' => '[0-9a-f-]{36}'])]
    public function promotePlaceholder(Request $request, string $id): Response
    {
        $user = $this->users->find($id) ?? throw $this->createNotFoundException();
        if (!$user->isPlaceholder()) {
            throw $this->createNotFoundException('User is not a placeholder.');
        }

        $form = $this->createForm(PromotePlaceholderType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $email = (string) $form->get('email')->getData();

            $existing = $this->users->findOneByEmail($email);
            if ($existing !== null && $existing !== $user) {
                $form->get('email')->addError(new \Symfony\Component\Form\FormError('A user with this email already exists.'));
            } else {
                $user->setEmail($email);
                $user->setPassword($this->hasher->hashPassword($user, (string) $form->get('password')->getData()));
                $user->setRoles($form->get('roles')->getData());
                $user->setPlaceholder(false);
                $this->em->flush();

                $this->addFlash('success', sprintf('Promoted %s.', $user->getFullName()));
                return $this->redirectToRoute('app_admin_user_show', ['id' => $user->getId()]);
            }
        }

        $status = $form->isSubmitted() ? 422 : 200;
        return $this->render('admin/user/promote.html.twig', [
            'user' => $user,
            'form' => $form->createView(),
        ], new Response(null, $status));
    }
}
