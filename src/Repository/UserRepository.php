<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\PasswordUpgraderInterface;

/**
 * @extends ServiceEntityRepository<User>
 */
class UserRepository extends ServiceEntityRepository implements PasswordUpgraderInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, User::class);
    }

    public function findOneByEmail(string $email): ?User
    {
        return $this->findOneBy(['email' => $email]);
    }

    public function findOneByFullName(string $fullName): ?User
    {
        return $this->findOneBy(['fullName' => $fullName]);
    }

    /**
     * @param array{search?: string|null, role?: string|null, placeholder?: bool|null} $filters
     */
    public function queryForAdminList(array $filters): \Doctrine\ORM\QueryBuilder
    {
        $qb = $this->createQueryBuilder('u')->orderBy('u.fullName', 'ASC');

        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            $qb->andWhere('LOWER(u.fullName) LIKE :s OR LOWER(u.email) LIKE :s')
               ->setParameter('s', '%' . strtolower($search) . '%');
        }

        $role = $filters['role'] ?? null;
        if (is_string($role) && $role !== '') {
            $qb->andWhere('CAST(u.roles AS TEXT) LIKE :role')
               ->setParameter('role', '%"' . $role . '"%');
        }

        if (array_key_exists('placeholder', $filters) && is_bool($filters['placeholder'])) {
            $qb->andWhere('u.placeholder = :ph')->setParameter('ph', $filters['placeholder']);
        }

        return $qb;
    }

    public function countDecisionReferences(User $user): int
    {
        $dql = 'SELECT COUNT(d.id) FROM App\Entity\Decision d
                WHERE d.submittedBy = :u OR d.approvedBy = :u OR d.followUpOwner = :u';
        return (int) $this->getEntityManager()->createQuery($dql)
            ->setParameter('u', $user)
            ->getSingleScalarResult();
    }

    public function upgradePassword(PasswordAuthenticatedUserInterface $user, string $newHashedPassword): void
    {
        if (!$user instanceof User) {
            throw new UnsupportedUserException(sprintf('Instances of "%s" are not supported.', $user::class));
        }

        $user->setPassword($newHashedPassword);
        $this->getEntityManager()->persist($user);
        $this->getEntityManager()->flush();
    }
}
