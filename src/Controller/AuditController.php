<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\DecisionHistoryRepository;
use Doctrine\ORM\Tools\Pagination\Paginator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_APPROVER')]
#[Route('/audit')]
final class AuditController extends AbstractController
{
    private const PAGE_SIZE = 50;

    #[Route('', name: 'app_audit_index', methods: ['GET'])]
    public function index(Request $request, DecisionHistoryRepository $repository): Response
    {
        $filters = [
            'from' => $request->query->get('from'),
            'to' => $request->query->get('to'),
            'actor' => $request->query->get('actor'),
            'field' => $request->query->get('field'),
            'q' => $request->query->get('q'),
        ];

        $page = max(1, (int) $request->query->get('page', 1));

        $qb = $repository->queryByFilters($filters)
            ->setFirstResult(($page - 1) * self::PAGE_SIZE)
            ->setMaxResults(self::PAGE_SIZE);

        $paginator = new Paginator($qb->getQuery(), fetchJoinCollection: false);
        $total = count($paginator);
        $totalPages = max(1, (int) ceil($total / self::PAGE_SIZE));

        return $this->render('audit/index.html.twig', [
            'entries' => $paginator,
            'filters' => $filters,
            'page' => $page,
            'totalPages' => $totalPages,
            'total' => $total,
        ]);
    }
}
