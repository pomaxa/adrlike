<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Decision;
use App\Entity\DecisionHistory;
use App\Entity\User;
use App\Enum\Department;
use App\Enum\Product;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class AuditControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private User $user;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);

        $user = $this->em->getRepository(User::class)->findOneBy(['email' => 'auditor@example.com']);
        if (!$user) {
            $user = new User('auditor@example.com', 'Auditor');
            $user->setRoles(['ROLE_ADMIN', 'ROLE_APPROVER', 'ROLE_SUBMITTER']);
            $user->setPassword($hasher->hashPassword($user, 'test'));
            $this->em->persist($user);
            $this->em->flush();
        }
        $this->user = $user;
        $this->client->loginUser($user);
    }

    public function testCreatingAndUpdatingDecisionProducesHistory(): void
    {
        $decision = new Decision();
        $decision->setDecidedAt(new \DateTimeImmutable('2026-04-20'));
        $decision->setProduct(Product::AllProduct);
        $decision->setDepartment(Department::Risk);
        $decision->setChangeDescription('Initial change text');
        $decision->setSubmittedBy($this->user);
        $this->em->persist($decision);
        $this->em->flush();

        $decision->setComment('Second-pass comment');
        $decision->setChangeDescription('Updated change text');
        $this->em->flush();

        $rows = $this->em->getRepository(DecisionHistory::class)
            ->findBy(['decision' => $decision], ['changedAt' => 'ASC']);

        $fields = array_map(fn (DecisionHistory $h) => $h->getFieldName(), $rows);
        self::assertContains(DecisionHistory::FIELD_CREATED, $fields, 'creation event missing');
        self::assertContains('comment', $fields, 'comment change not tracked');
        self::assertContains('changeDescription', $fields, 'changeDescription change not tracked');

        $creation = array_values(array_filter($rows, fn ($h) => $h->isCreation()))[0];
        self::assertSame($this->user->getId()->toRfc4122(), $creation->getChangedBy()?->getId()?->toRfc4122());
    }

    public function testAuditIndexRendersForApprover(): void
    {
        $this->client->request('GET', '/audit');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Audit log');
    }

    public function testAuditIndexForbiddenForPlainSubmitter(): void
    {
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $submitter = $this->em->getRepository(User::class)->findOneBy(['email' => 'submitter@example.com']);
        if (!$submitter) {
            $submitter = new User('submitter@example.com', 'Sub');
            $submitter->setRoles(['ROLE_SUBMITTER']);
            $submitter->setPassword($hasher->hashPassword($submitter, 'test'));
            $this->em->persist($submitter);
            $this->em->flush();
        }
        $this->client->loginUser($submitter);
        $this->client->request('GET', '/audit');
        self::assertResponseStatusCodeSame(403);
    }
}
