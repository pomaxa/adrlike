<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Uid\Uuid;

#[AsCommand(
    name: 'app:users:merge',
    description: 'Merge one user (source) into another (target): reassigns all decisions and removes the source user.',
)]
final class MergeUsersCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserRepository $users,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('source', InputArgument::OPTIONAL, 'Source user (email, UUID, or full name). Will be deleted.')
            ->addArgument('target', InputArgument::OPTIONAL, 'Target user (email, UUID, or full name). Will receive everything.')
            ->addOption('yes', 'y', InputOption::VALUE_NONE, 'Skip confirmation');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $allUsers = $this->users->createQueryBuilder('u')->orderBy('u.fullName', 'ASC')->getQuery()->getResult();
        if (count($allUsers) < 2) {
            $io->warning('Need at least two users to merge.');

            return Command::SUCCESS;
        }

        $rows = [];
        foreach ($allUsers as $u) {
            $rows[] = [
                (string) $u->getId(),
                $u->getFullName(),
                $u->getEmail(),
                $u->isPlaceholder() ? 'yes' : 'no',
                $this->decisionCountFor($u),
            ];
        }
        $io->title('Users');
        $io->table(['id', 'full name', 'email', 'placeholder', 'decisions'], $rows);

        $sourceArg = $input->getArgument('source') ?? $io->ask('Source user (email/UUID/name) — will be DELETED');
        $targetArg = $input->getArgument('target') ?? $io->ask('Target user (email/UUID/name) — will receive everything');

        $source = $this->resolveUser((string) $sourceArg);
        $target = $this->resolveUser((string) $targetArg);

        if (!$source || !$target) {
            $io->error('Could not resolve both users.');

            return Command::FAILURE;
        }
        if ($source->getId()->equals($target->getId())) {
            $io->error('Source and target must be different.');

            return Command::FAILURE;
        }
        if (in_array('ROLE_ADMIN', $source->getRoles(), true)) {
            $io->error('Refusing to delete an ROLE_ADMIN user. Demote first.');

            return Command::FAILURE;
        }

        $io->section('Plan');
        $io->definitionList(
            ['Source (will be removed)' => sprintf('%s <%s>', $source->getFullName(), $source->getEmail())],
            ['Target (will remain)' => sprintf('%s <%s>', $target->getFullName(), $target->getEmail())],
            ['Decisions to reassign' => (string) $this->decisionCountFor($source)],
        );

        if (!$input->getOption('yes') && !$io->confirm('Proceed?', false)) {
            $io->warning('Aborted.');

            return Command::SUCCESS;
        }

        $conn = $this->em->getConnection();
        $conn->beginTransaction();
        try {
            $sourceId = $source->getId()->toBinary();
            $targetId = $target->getId()->toBinary();

            $counts = [
                'submitted_by' => $conn->executeStatement(
                    'UPDATE decisions SET submitted_by_id = :t WHERE submitted_by_id = :s',
                    ['s' => $sourceId, 't' => $targetId]
                ),
                'approved_by' => $conn->executeStatement(
                    'UPDATE decisions SET approved_by_id = :t WHERE approved_by_id = :s',
                    ['s' => $sourceId, 't' => $targetId]
                ),
                'follow_up_owner' => $conn->executeStatement(
                    'UPDATE decisions SET follow_up_owner_id = :t WHERE follow_up_owner_id = :s',
                    ['s' => $sourceId, 't' => $targetId]
                ),
                'history_changed_by' => $conn->executeStatement(
                    'UPDATE decision_history SET changed_by_id = :t WHERE changed_by_id = :s',
                    ['s' => $sourceId, 't' => $targetId]
                ),
            ];

            $conn->executeStatement('DELETE FROM users WHERE id = :s', ['s' => $sourceId]);
            $conn->commit();
        } catch (\Throwable $e) {
            $conn->rollBack();
            $io->error('Merge failed: ' . $e->getMessage());

            return Command::FAILURE;
        }

        $io->success(sprintf(
            'Merged. Rows updated — submitted_by: %d, approved_by: %d, follow_up_owner: %d, history: %d.',
            $counts['submitted_by'], $counts['approved_by'], $counts['follow_up_owner'], $counts['history_changed_by']
        ));

        return Command::SUCCESS;
    }

    private function decisionCountFor(User $u): int
    {
        return (int) $this->em->createQuery(
            'SELECT COUNT(d.id) FROM App\\Entity\\Decision d
             WHERE d.submittedBy = :u OR d.approvedBy = :u OR d.followUpOwner = :u'
        )->setParameter('u', $u)->getSingleScalarResult();
    }

    private function resolveUser(string $ref): ?User
    {
        $ref = trim($ref);
        if ($ref === '') {
            return null;
        }

        if (Uuid::isValid($ref)) {
            $byId = $this->users->find(Uuid::fromString($ref));
            if ($byId) {
                return $byId;
            }
        }
        if (str_contains($ref, '@')) {
            $byEmail = $this->users->findOneByEmail($ref);
            if ($byEmail) {
                return $byEmail;
            }
        }

        $byName = $this->users->findOneByFullName($ref);
        if ($byName) {
            return $byName;
        }

        $matches = $this->users->createQueryBuilder('u')
            ->where('LOWER(u.fullName) LIKE :ref OR LOWER(u.email) LIKE :ref')
            ->setParameter('ref', '%' . mb_strtolower($ref) . '%')
            ->getQuery()
            ->getResult();

        return count($matches) === 1 ? $matches[0] : null;
    }
}
