<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Repository\UserRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_ADMIN')]
#[Route('/admin/users')]
final class UserController extends AbstractController
{
    public function __construct(private readonly UserRepository $users)
    {
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
}
