<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Product;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Product>
 */
class ProductRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Product::class);
    }

    /**
     * @return Product[]
     */
    public function findAllOrderedByName(): array
    {
        return $this->createQueryBuilder('p')
            ->orderBy('p.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findOneByName(string $name): ?Product
    {
        return $this->findOneBy(['name' => $name]);
    }

    public function countDecisions(Product $product): int
    {
        return (int) $this->getEntityManager()
            ->createQuery('SELECT COUNT(d.id) FROM App\Entity\Decision d WHERE d.product = :p')
            ->setParameter('p', $product)
            ->getSingleScalarResult();
    }
}
