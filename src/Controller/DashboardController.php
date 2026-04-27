<?php

declare(strict_types=1);

namespace App\Controller;

use App\Enum\Department;
use App\Enum\FollowUpStatus;
use App\Repository\DecisionRepository;
use App\Repository\ProductRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_SUBMITTER')]
final class DashboardController extends AbstractController
{
    public function __construct(
        private readonly DecisionRepository $decisions,
        private readonly ProductRepository $products,
    ) {
    }

    #[Route('/', name: 'app_dashboard', methods: ['GET'])]
    public function index(): Response
    {
        $today = new \DateTimeImmutable('today');
        $overdue = $this->decisions->findOverdueFollowUps($today);
        $upcoming = $this->decisions->findUpcomingFollowUps($today->modify('+1 day'), $today->modify('+14 days'));

        $recent = $this->decisions->createQueryBuilder('d')
            ->leftJoin('d.submittedBy', 'sb')->addSelect('sb')
            ->orderBy('d.decidedAt', 'DESC')
            ->addOrderBy('d.createdAt', 'DESC')
            ->setMaxResults(8)
            ->getQuery()
            ->getResult();

        $total = (int) $this->decisions->createQueryBuilder('d')
            ->select('COUNT(d.id)')
            ->getQuery()
            ->getSingleScalarResult();

        $rawProductCounts = $this->decisions->createQueryBuilder('d')
            ->select('IDENTITY(d.product) AS pid, COUNT(d.id) AS cnt')
            ->groupBy('d.product')
            ->getQuery()
            ->getArrayResult();
        $productCountMap = array_column($rawProductCounts, 'cnt', 'pid');
        $byProduct = array_map(
            static fn ($p) => ['label' => $p->label(), 'count' => (int) ($productCountMap[(string) $p->getId()] ?? 0)],
            $this->products->findAllOrderedByName(),
        );

        $byDepartment = $this->decisions->createQueryBuilder('d')
            ->select('d.department AS department, COUNT(d.id) AS cnt')
            ->groupBy('d.department')
            ->getQuery()
            ->getArrayResult();

        foreach ($overdue as $d) {
            $d->recomputeFollowUpStatus($today);
        }

        return $this->render('dashboard/index.html.twig', [
            'today' => $today,
            'total' => $total,
            'overdue' => $overdue,
            'upcoming' => $upcoming,
            'recent' => $recent,
            'by_product' => $byProduct,
            'by_department' => self::normalizeCounts($byDepartment, 'department', Department::cases()),
            'status_counts' => $this->statusCounts(),
        ]);
    }

    /**
     * @param list<array{follow_up_status?: mixed}> $rows
     */
    /**
     * @return array<string, int>
     */
    private function statusCounts(): array
    {
        $raw = $this->decisions->createQueryBuilder('d')
            ->select('d.followUpStatus AS status, COUNT(d.id) AS cnt')
            ->groupBy('d.followUpStatus')
            ->getQuery()
            ->getArrayResult();

        $out = [];
        foreach (FollowUpStatus::cases() as $c) {
            $out[$c->value] = 0;
        }
        foreach ($raw as $r) {
            $key = $r['status'] instanceof FollowUpStatus ? $r['status']->value : (string) $r['status'];
            $out[$key] = (int) $r['cnt'];
        }

        return $out;
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @param list<\BackedEnum> $enumCases
     * @return list<array{label: string, value: string, count: int}>
     */
    private static function normalizeCounts(array $rows, string $key, array $enumCases): array
    {
        $byValue = [];
        foreach ($enumCases as $case) {
            $byValue[$case->value] = 0;
        }
        foreach ($rows as $r) {
            $v = $r[$key];
            $value = $v instanceof \BackedEnum ? $v->value : (string) $v;
            $byValue[$value] = ($byValue[$value] ?? 0) + (int) $r['cnt'];
        }

        $out = [];
        foreach ($enumCases as $case) {
            $label = method_exists($case, 'label') ? $case->label() : $case->value;
            $out[] = ['label' => $label, 'value' => $case->value, 'count' => $byValue[$case->value] ?? 0];
        }

        return $out;
    }
}
