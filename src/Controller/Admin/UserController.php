<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\User;
use App\Form\UserCreateType;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
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
        return new Response('stub', 501);
    }

    #[Route('/{id}/password', name: 'app_admin_user_password', methods: ['GET', 'POST'], requirements: ['id' => '[0-9a-f-]{36}'])]
    public function resetPassword(Request $request, string $id): Response
    {
        return new Response('stub', 501);
    }

    #[Route('/{id}/promote', name: 'app_admin_user_promote', methods: ['GET', 'POST'], requirements: ['id' => '[0-9a-f-]{36}'])]
    public function promotePlaceholder(Request $request, string $id): Response
    {
        return new Response('stub', 501);
    }
}
