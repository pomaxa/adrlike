<?php

declare(strict_types=1);

namespace App\Tests\MessageHandler;

use App\Entity\Decision;
use App\Entity\User;
use App\Enum\Department;
use App\Enum\FollowUpStatus;
use App\Enum\Product;
use App\Message\SendFollowUpReminders;
use App\MessageHandler\SendFollowUpRemindersHandler;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class SendFollowUpRemindersHandlerTest extends KernelTestCase
{
    public function testDispatchesEmailForOverdueOwner(): void
    {
        self::bootKernel(['environment' => 'test']);
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $em->createQuery('DELETE FROM ' . Decision::class)->execute();
        $em->createQuery('DELETE FROM ' . User::class . ' u WHERE u.email LIKE :e')
            ->setParameter('e', '%@ownertest.local')
            ->execute();

        $owner = new User('owner@ownertest.local', 'Owner One');
        $owner->setRoles(['ROLE_SUBMITTER']);
        $em->persist($owner);

        $decision = new Decision();
        $decision->setDecidedAt(new \DateTimeImmutable('-10 days'));
        $decision->setProduct(Product::Leasing);
        $decision->setDepartment(Department::Risk);
        $decision->setChangeDescription('Test overdue decision');
        $decision->setSubmittedBy($owner);
        $decision->setFollowUpOwner($owner);
        $decision->setFollowUpDate(new \DateTimeImmutable('-2 days'));
        $decision->setFollowUpStatus(FollowUpStatus::Pending);
        $em->persist($decision);
        $em->flush();

        /** @var SendFollowUpRemindersHandler $handler */
        $handler = static::getContainer()->get(SendFollowUpRemindersHandler::class);
        $handler(new SendFollowUpReminders());

        $messages = static::getMailerMessages();
        self::assertGreaterThanOrEqual(1, \count($messages), 'Handler should dispatch at least one reminder email');
        $body = $messages[0]->getBody()->bodyToString();
        self::assertStringContainsString('Test overdue decision', $body);
    }
}
