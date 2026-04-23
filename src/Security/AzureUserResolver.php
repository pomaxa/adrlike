<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;

final class AzureUserResolver
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function resolve(string $email, string $displayName): User
    {
        $existing = $this->users->findOneByEmail($email);
        if ($existing !== null) {
            if ($existing->isPlaceholder()) {
                $existing->setPlaceholder(false);
                $this->em->flush();
            }
            return $existing;
        }

        $placeholderMatches = $this->users->createQueryBuilder('u')
            ->where('u.placeholder = true')
            ->andWhere('u.fullName = :n')
            ->setParameter('n', $displayName)
            ->getQuery()
            ->getResult();

        if (count($placeholderMatches) === 1) {
            /** @var User $match */
            $match = $placeholderMatches[0];
            $match->setEmail($email);
            $match->setPlaceholder(false);
            $this->em->flush();
            return $match;
        }

        $user = new User($email, $displayName);
        $user->setRoles(['ROLE_SUBMITTER']);
        $user->setPlaceholder(false);
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }
}
