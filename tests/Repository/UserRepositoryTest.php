<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class UserRepositoryTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private UserRepository $repo;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->repo = $this->em->getRepository(User::class);

        $this->em->createQuery('DELETE FROM App\Entity\DecisionHistory dh')->execute();
        $this->em->createQuery('DELETE FROM App\Entity\Decision d')->execute();
        $this->em->createQuery('DELETE FROM App\Entity\User u')->execute();

        $alice = new User('alice@example.com', 'Alice Real');
        $alice->setRoles(['ROLE_ADMIN']);
        $this->em->persist($alice);

        $bob = new User('bob@example.com', 'Bob Submitter');
        $bob->setRoles(['ROLE_SUBMITTER']);
        $this->em->persist($bob);

        $ghost = new User('ghost-one@imported.local', 'Ghost One');
        $ghost->setPlaceholder(true);
        $this->em->persist($ghost);

        $this->em->flush();
    }

    public function testQueryForAdminListReturnsAllUsersSortedByName(): void
    {
        $users = $this->repo->queryForAdminList([])->getQuery()->getResult();
        $names = array_map(fn (User $u) => $u->getFullName(), $users);

        self::assertSame(['Alice Real', 'Bob Submitter', 'Ghost One'], $names);
    }

    public function testQueryForAdminListFiltersBySearchOnNameAndEmail(): void
    {
        $users = $this->repo->queryForAdminList(['search' => 'bob'])->getQuery()->getResult();
        self::assertCount(1, $users);
        self::assertSame('Bob Submitter', $users[0]->getFullName());

        $users = $this->repo->queryForAdminList(['search' => 'ghost-one@imported'])->getQuery()->getResult();
        self::assertCount(1, $users);
        self::assertSame('Ghost One', $users[0]->getFullName());
    }

    public function testQueryForAdminListFiltersByRole(): void
    {
        $users = $this->repo->queryForAdminList(['role' => 'ROLE_ADMIN'])->getQuery()->getResult();
        self::assertCount(1, $users);
        self::assertSame('Alice Real', $users[0]->getFullName());
    }

    public function testQueryForAdminListFiltersPlaceholders(): void
    {
        $users = $this->repo->queryForAdminList(['placeholder' => true])->getQuery()->getResult();
        self::assertCount(1, $users);
        self::assertSame('Ghost One', $users[0]->getFullName());

        $users = $this->repo->queryForAdminList(['placeholder' => false])->getQuery()->getResult();
        self::assertCount(2, $users);
    }
}
