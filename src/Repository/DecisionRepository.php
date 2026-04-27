<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Decision;
use App\Entity\Product;
use App\Enum\FollowUpStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Decision>
 */
class DecisionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Decision::class);
    }

    public function findOneByImportHash(string $hash): ?Decision
    {
        return $this->findOneBy(['importHash' => $hash]);
    }

    /**
     * @param array{product?: ?Product, department?: ?string, status?: ?string, q?: ?string} $criteria
     */
    public function queryByFilters(array $criteria): QueryBuilder
    {
        $qb = $this->createQueryBuilder('d')
            ->leftJoin('d.submittedBy', 'sb')->addSelect('sb')
            ->leftJoin('d.approvedBy', 'ab')->addSelect('ab')
            ->leftJoin('d.followUpOwner', 'fo')->addSelect('fo')
            ->orderBy('d.decidedAt', 'DESC')
            ->addOrderBy('d.createdAt', 'DESC');

        if (!empty($criteria['product'])) {
            $qb->andWhere('d.product = :product')->setParameter('product', $criteria['product']);
        }
        if (!empty($criteria['department'])) {
            $qb->andWhere('d.department = :department')->setParameter('department', $criteria['department']);
        }
        if (!empty($criteria['status'])) {
            $qb->andWhere('d.followUpStatus = :status')->setParameter('status', $criteria['status']);
        }
        if (!empty($criteria['q'])) {
            $qb->andWhere('LOWER(d.changeDescription) LIKE :q OR LOWER(COALESCE(d.comment, \'\')) LIKE :q')
                ->setParameter('q', '%' . mb_strtolower((string) $criteria['q']) . '%');
        }

        return $qb;
    }

    /**
     * @return Decision[]
     */
    public function findOverdueFollowUps(\DateTimeImmutable $today): array
    {
        return $this->createQueryBuilder('d')
            ->leftJoin('d.followUpOwner', 'fo')->addSelect('fo')
            ->leftJoin('d.submittedBy', 'sb')->addSelect('sb')
            ->andWhere('d.followUpStatus IN (:statuses)')
            ->andWhere('d.followUpDate IS NOT NULL')
            ->andWhere('d.followUpDate <= :today')
            ->setParameter('statuses', [FollowUpStatus::Pending, FollowUpStatus::Overdue])
            ->setParameter('today', $today->format('Y-m-d'))
            ->orderBy('d.followUpDate', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return Decision[]
     */
    public function findUpcomingFollowUps(\DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        return $this->createQueryBuilder('d')
            ->leftJoin('d.followUpOwner', 'fo')->addSelect('fo')
            ->andWhere('d.followUpStatus = :status')
            ->andWhere('d.followUpDate BETWEEN :from AND :to')
            ->setParameter('status', FollowUpStatus::Pending)
            ->setParameter('from', $from->format('Y-m-d'))
            ->setParameter('to', $to->format('Y-m-d'))
            ->orderBy('d.followUpDate', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
