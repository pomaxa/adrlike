<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\DecisionHistory;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<DecisionHistory>
 */
class DecisionHistoryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DecisionHistory::class);
    }

    /**
     * @param array{from?: ?string, to?: ?string, actor?: ?string, field?: ?string, q?: ?string} $filters
     */
    public function queryByFilters(array $filters): QueryBuilder
    {
        $qb = $this->createQueryBuilder('h')
            ->leftJoin('h.changedBy', 'u')->addSelect('u')
            ->leftJoin('h.decision', 'd')->addSelect('d')
            ->orderBy('h.changedAt', 'DESC');

        if (!empty($filters['from'])) {
            try {
                $from = new \DateTimeImmutable($filters['from']);
                $qb->andWhere('h.changedAt >= :from')->setParameter('from', $from);
            } catch (\Exception) {
            }
        }

        if (!empty($filters['to'])) {
            try {
                $to = (new \DateTimeImmutable($filters['to']))->setTime(23, 59, 59);
                $qb->andWhere('h.changedAt <= :to')->setParameter('to', $to);
            } catch (\Exception) {
            }
        }

        if (!empty($filters['actor'])) {
            $qb->andWhere('LOWER(u.fullName) LIKE :actor OR LOWER(u.email) LIKE :actor')
                ->setParameter('actor', '%' . mb_strtolower($filters['actor']) . '%');
        }

        if (!empty($filters['field'])) {
            $qb->andWhere('h.fieldName = :field')->setParameter('field', $filters['field']);
        }

        if (!empty($filters['q'])) {
            $qb->andWhere('LOWER(h.oldValue) LIKE :q OR LOWER(h.newValue) LIKE :q OR LOWER(d.changeDescription) LIKE :q')
                ->setParameter('q', '%' . mb_strtolower($filters['q']) . '%');
        }

        return $qb;
    }
}
