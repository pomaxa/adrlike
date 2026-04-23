<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Entity\Decision;
use App\Message\SendFollowUpReminders;
use App\Repository\DecisionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Mime\Email;

#[AsMessageHandler]
final class SendFollowUpRemindersHandler
{
    public function __construct(
        private readonly DecisionRepository $decisions,
        private readonly EntityManagerInterface $em,
        private readonly MailerInterface $mailer,
        private readonly LoggerInterface $logger,
        private readonly string $fromAddress = 'decisions@local.dev',
    ) {
    }

    public function __invoke(SendFollowUpReminders $_): void
    {
        $today = new \DateTimeImmutable('today');
        $horizon = $today->modify('+3 days');

        $overdue = $this->decisions->findOverdueFollowUps($today);
        $upcoming = $this->decisions->findUpcomingFollowUps($today->modify('+1 day'), $horizon);

        /** @var array<string, array{user: \App\Entity\User, overdue: Decision[], upcoming: Decision[]}> $byOwner */
        $byOwner = [];

        foreach ($overdue as $d) {
            $d->recomputeFollowUpStatus($today);
            $this->bucket($byOwner, $d, 'overdue');
        }
        foreach ($upcoming as $d) {
            $this->bucket($byOwner, $d, 'upcoming');
        }

        $this->em->flush();

        $sent = 0;
        foreach ($byOwner as $entry) {
            $user = $entry['user'];
            if ($user->getEmail() === '' || str_ends_with($user->getEmail(), '@imported.local')) {
                $this->logger->info('Skipping reminder for placeholder user', ['user' => $user->getEmail()]);
                continue;
            }

            $email = (new Email())
                ->from($this->fromAddress)
                ->to($user->getEmail())
                ->subject(sprintf('[Decisions] %d overdue, %d upcoming follow-ups', count($entry['overdue']), count($entry['upcoming'])))
                ->text($this->formatBody($entry['overdue'], $entry['upcoming']));
            $this->mailer->send($email);
            ++$sent;
        }

        $this->logger->info('Follow-up reminders dispatched', ['sent' => $sent, 'overdue' => count($overdue), 'upcoming' => count($upcoming)]);
    }

    /**
     * @param array<string, array{user: \App\Entity\User, overdue: Decision[], upcoming: Decision[]}> $bucket
     */
    private function bucket(array &$bucket, Decision $d, string $slot): void
    {
        $owner = $d->getFollowUpOwner() ?? $d->getSubmittedBy();
        $key = $owner->getEmail();
        if (!isset($bucket[$key])) {
            $bucket[$key] = ['user' => $owner, 'overdue' => [], 'upcoming' => []];
        }
        $bucket[$key][$slot][] = $d;
    }

    /**
     * @param Decision[] $overdue
     * @param Decision[] $upcoming
     */
    private function formatBody(array $overdue, array $upcoming): string
    {
        $lines = [];
        if ($overdue !== []) {
            $lines[] = 'OVERDUE:';
            foreach ($overdue as $d) {
                $lines[] = sprintf('  - %s (due %s): %s', $d->getProduct()->label(), $d->getFollowUpDate()?->format('Y-m-d'), self::snippet($d->getChangeDescription()));
            }
        }
        if ($upcoming !== []) {
            $lines[] = '';
            $lines[] = 'UPCOMING (next 3 days):';
            foreach ($upcoming as $d) {
                $lines[] = sprintf('  - %s (due %s): %s', $d->getProduct()->label(), $d->getFollowUpDate()?->format('Y-m-d'), self::snippet($d->getChangeDescription()));
            }
        }

        return implode("\n", $lines);
    }

    private static function snippet(string $text): string
    {
        $text = preg_replace('/\s+/', ' ', $text) ?? $text;

        return mb_strlen($text) > 120 ? mb_substr($text, 0, 117) . '...' : $text;
    }
}
