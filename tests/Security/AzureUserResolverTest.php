<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Security\AzureUserResolver;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class AzureUserResolverTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private UserRepository $repo;
    private AzureUserResolver $resolver;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->repo = $this->em->getRepository(User::class);
        $this->resolver = self::getContainer()->get(AzureUserResolver::class);
        // FK-safe cleanup
        $this->em->createQuery('DELETE FROM App\Entity\DecisionHistory h')->execute();
        $this->em->createQuery('DELETE FROM App\Entity\Decision d')->execute();
        $this->em->createQuery('DELETE FROM App\Entity\User u')->execute();
    }

    public function testReusesRealUserByEmail(): void
    {
        $existing = new User('alice@example.com', 'Alice');
        $existing->setRoles(['ROLE_APPROVER']);
        $this->em->persist($existing);
        $this->em->flush();
        $originalId = $existing->getId()->toRfc4122();

        $u = $this->resolver->resolve('alice@example.com', 'Alice From SSO');

        self::assertSame($originalId, $u->getId()->toRfc4122());
        self::assertFalse($u->isPlaceholder());
    }

    public function testPromotesPlaceholderMatchingOnEmail(): void
    {
        $ph = new User('alice@example.com', 'Alice');
        $ph->setPlaceholder(true);
        $this->em->persist($ph);
        $this->em->flush();
        $originalId = $ph->getId()->toRfc4122();

        $u = $this->resolver->resolve('alice@example.com', 'Alice');

        self::assertSame($originalId, $u->getId()->toRfc4122());
        self::assertFalse($u->isPlaceholder());
    }

    public function testPromotesPlaceholderByExactNameWhenEmailDoesNotMatch(): void
    {
        $ph = new User('alice-slug@imported.local', 'Alice Wonderland');
        $ph->setPlaceholder(true);
        $this->em->persist($ph);
        $this->em->flush();
        $originalId = $ph->getId()->toRfc4122();

        $u = $this->resolver->resolve('alice.w@corp.example.com', 'Alice Wonderland');

        self::assertSame($originalId, $u->getId()->toRfc4122());
        self::assertSame('alice.w@corp.example.com', $u->getEmail());
        self::assertFalse($u->isPlaceholder());
    }

    public function testCreatesNewUserWhenNoMatches(): void
    {
        $u = $this->resolver->resolve('brand.new@corp.example.com', 'Brand New');
        self::assertNotNull($u->getId());
        self::assertSame('Brand New', $u->getFullName());
        self::assertContains('ROLE_SUBMITTER', $u->getRoles());
        self::assertFalse($u->isPlaceholder());
        self::assertNull($u->getPassword());
    }

    public function testCreatesNewUserWhenMultiplePlaceholdersShareName(): void
    {
        $a = new User('one@imported.local', 'John Doe');
        $a->setPlaceholder(true);
        $b = new User('two@imported.local', 'John Doe');
        $b->setPlaceholder(true);
        $this->em->persist($a);
        $this->em->persist($b);
        $this->em->flush();

        $u = $this->resolver->resolve('john.doe@corp.example.com', 'John Doe');

        self::assertNotSame($a->getId()->toRfc4122(), $u->getId()->toRfc4122());
        self::assertNotSame($b->getId()->toRfc4122(), $u->getId()->toRfc4122());
    }
}
