<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Decision;
use App\Entity\User;
use App\Enum\Department;
use App\Enum\FollowUpStatus;
use App\Enum\Product;
use App\Repository\DecisionRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use League\Csv\Reader;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:import-csv',
    description: 'Import decisions from the legacy Product_changes CSV file.',
)]
final class ImportCsvCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly DecisionRepository $decisions,
        private readonly UserRepository $users,
    ) {
        parent::__construct();
    }

    /** @var list<string> */
    private const CANDIDATE_ENCODINGS = [
        'UTF-8',
        'Windows-1251',
        'Windows-1252',
        'KOI8-R',
        'ISO-8859-1',
        'ISO-8859-5',
        'CP866',
    ];

    protected function configure(): void
    {
        $this
            ->addArgument('file', InputArgument::REQUIRED, 'Path to the CSV file')
            ->addOption('encoding', null, InputOption::VALUE_REQUIRED, 'Source encoding (default: auto-detect)', 'auto')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Parse but do not persist');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $path = (string) $input->getArgument('file');
        if (!is_file($path) || !is_readable($path)) {
            $io->error("Cannot read file: {$path}");

            return Command::FAILURE;
        }

        $raw = (string) file_get_contents($path);
        $raw = self::stripBom($raw, $detectedBom);
        $encodingOpt = (string) $input->getOption('encoding');
        $sourceEncoding = strtolower($encodingOpt) === 'auto'
            ? ($detectedBom ?? self::detectEncoding($raw))
            : $encodingOpt;

        $io->text(sprintf('Source encoding: <info>%s</info>', $sourceEncoding));

        if (strcasecmp($sourceEncoding, 'UTF-8') !== 0) {
            $utf8 = @mb_convert_encoding($raw, 'UTF-8', $sourceEncoding);
            if ($utf8 === false) {
                $io->error("Could not transcode from {$sourceEncoding} to UTF-8.");

                return Command::FAILURE;
            }
            $raw = $utf8;
        }

        if (!mb_check_encoding($raw, 'UTF-8')) {
            $io->error('Input is not valid UTF-8 after conversion.');

            return Command::FAILURE;
        }

        $csv = Reader::createFromString($raw);
        $csv->setHeaderOffset(1);

        $stats = ['created' => 0, 'skipped' => 0, 'users' => 0];
        $userCache = [];

        foreach ($csv->getRecords() as $row) {
            $dateStr = trim((string) ($row['Date'] ?? ''));
            $change = trim((string) ($row['Change'] ?? ''));
            $submitterName = trim((string) ($row['Submitted by'] ?? ''));

            if ($dateStr === '' || $change === '' || $submitterName === '') {
                continue;
            }

            $decidedAt = \DateTimeImmutable::createFromFormat('!d.m.Y', $dateStr);
            if ($decidedAt === false) {
                $io->warning("Unparseable date: {$dateStr}, skipping row.");
                continue;
            }

            $importHash = self::hashRow($decidedAt, $submitterName, $change);
            if ($this->decisions->findOneByImportHash($importHash) !== null) {
                ++$stats['skipped'];
                continue;
            }

            $submittedBy = $this->resolveUser($submitterName, $userCache, $stats);
            $approvedBy = self::nonEmpty($row['Approved by'] ?? null);
            $approvedByUser = $approvedBy !== null ? $this->resolveUser($approvedBy, $userCache, $stats) : null;

            $product = self::resolveProduct((string) ($row['Product'] ?? ''));
            $department = self::resolveDepartment((string) ($row['Department'] ?? ''));

            $followUpDateStr = trim((string) ($row['Follow-up date'] ?? ''));
            $followUpDate = null;
            if ($followUpDateStr !== '') {
                $followUpDate = \DateTimeImmutable::createFromFormat('!d.m.Y', $followUpDateStr) ?: null;
            }

            $decision = new Decision();
            $decision->setDecidedAt($decidedAt);
            $decision->setProduct($product);
            $decision->setDepartment($department);
            $decision->setClientsType(self::nonEmpty($row['Clients type'] ?? null) ?? 'All');
            $decision->setChangeDescription($change);
            $decision->setComment(self::nonEmpty($row['Comment'] ?? null));
            $decision->setSubmittedBy($submittedBy);
            $decision->setApprovedBy($approvedByUser);
            $decision->setAsIsMetrics(self::wrapRawMetric($row['As is'] ?? null));
            $decision->setToBeMetrics(self::wrapRawMetric($row['To-be'] ?? null));
            $decision->setFollowUpDate($followUpDate);
            $decision->setActualResult(self::nonEmpty($row['Actual result'] ?? null));
            $decision->setImportHash($importHash);
            $decision->setFollowUpStatus($followUpDate !== null ? FollowUpStatus::Pending : FollowUpStatus::NotRequired);
            $decision->recomputeFollowUpStatus(new \DateTimeImmutable('today'));

            $this->em->persist($decision);
            ++$stats['created'];
        }

        if (!$input->getOption('dry-run')) {
            $this->em->flush();
        } else {
            $this->em->clear();
        }

        $io->success(sprintf(
            'Imported %d decisions, skipped %d duplicates, created %d users.',
            $stats['created'], $stats['skipped'], $stats['users']
        ));

        return Command::SUCCESS;
    }

    /** @var array<string, true> */
    private array $reservedEmails = [];

    private function resolveUser(string $fullName, array &$cache, array &$stats): User
    {
        $key = mb_strtolower($fullName);
        if (isset($cache[$key])) {
            return $cache[$key];
        }

        $existing = $this->users->findOneByFullName($fullName);
        if ($existing !== null) {
            return $cache[$key] = $existing;
        }

        $base = self::slug($fullName);
        $email = $base . '@imported.local';
        $dup = 1;
        while (isset($this->reservedEmails[$email]) || $this->users->findOneByEmail($email) !== null) {
            $email = $base . '+' . $dup . '@imported.local';
            ++$dup;
        }
        $this->reservedEmails[$email] = true;

        $user = new User($email, $fullName);
        $user->setRoles(['ROLE_SUBMITTER']);
        $user->setPassword(null);
        $user->setPlaceholder(true);
        $this->em->persist($user);
        ++$stats['users'];

        return $cache[$key] = $user;
    }

    private static function slug(string $value): string
    {
        $slug = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value) ?: $value;
        $slug = strtolower((string) preg_replace('/[^a-z0-9]+/i', '.', $slug));

        return trim($slug, '.') ?: 'user';
    }

    private static function nonEmpty(?string $value): ?string
    {
        $value = $value !== null ? trim($value) : null;

        return $value !== null && $value !== '' ? $value : null;
    }

    private static function wrapRawMetric(mixed $value): ?array
    {
        $trimmed = is_string($value) ? trim($value) : null;
        if ($trimmed === null || $trimmed === '') {
            return null;
        }

        return ['raw' => $trimmed];
    }

    private static function resolveProduct(string $raw): Product
    {
        $raw = trim($raw);

        return match (mb_strtolower($raw)) {
            'leasing' => Product::Leasing,
            'installment' => Product::Installment,
            'leaseback' => Product::Leaseback,
            default => Product::AllProduct,
        };
    }

    private static function resolveDepartment(string $raw): Department
    {
        $raw = trim($raw);

        return match (mb_strtolower($raw)) {
            'risk' => Department::Risk,
            'manual' => Department::Manual,
            default => Department::Other,
        };
    }

    private static function hashRow(\DateTimeImmutable $decidedAt, string $submitter, string $change): string
    {
        return hash('sha256', $decidedAt->format('Y-m-d') . '|' . mb_strtolower($submitter) . '|' . $change);
    }

    private static function stripBom(string $raw, ?string &$detectedBom): string
    {
        $detectedBom = null;
        $boms = [
            "\xEF\xBB\xBF" => 'UTF-8',
            "\xFE\xFF" => 'UTF-16BE',
            "\xFF\xFE" => 'UTF-16LE',
            "\x00\x00\xFE\xFF" => 'UTF-32BE',
            "\xFF\xFE\x00\x00" => 'UTF-32LE',
        ];
        foreach ($boms as $mark => $name) {
            if (str_starts_with($raw, $mark)) {
                $detectedBom = $name;

                return substr($raw, strlen($mark));
            }
        }

        return $raw;
    }

    private static function detectEncoding(string $raw): string
    {
        $sample = mb_strlen($raw, '8bit') > 262144 ? substr($raw, 0, 262144) : $raw;

        if (mb_check_encoding($sample, 'UTF-8')) {
            return 'UTF-8';
        }

        $best = null;
        $bestScore = -1.0;
        foreach (self::CANDIDATE_ENCODINGS as $candidate) {
            $converted = @mb_convert_encoding($sample, 'UTF-8', $candidate);
            if ($converted === false || !mb_check_encoding($converted, 'UTF-8')) {
                continue;
            }
            $score = self::scoreText($converted);
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $candidate;
            }
        }

        return $best ?? 'UTF-8';
    }

    private static function scoreText(string $utf8): float
    {
        $len = mb_strlen($utf8);
        if ($len === 0) {
            return 0.0;
        }
        $printable = 0;
        $cyrillic = 0;
        $replacement = 0;
        $offset = 0;
        while ($offset < $len) {
            $ch = mb_substr($utf8, $offset, 1);
            ++$offset;
            $cp = mb_ord($ch);
            if ($cp === false) {
                continue;
            }
            if ($cp === 0xFFFD) {
                ++$replacement;
                continue;
            }
            if ($cp === 0x09 || $cp === 0x0A || $cp === 0x0D || ($cp >= 0x20 && $cp !== 0x7F)) {
                ++$printable;
            }
            if (($cp >= 0x0400 && $cp <= 0x04FF) || $cp === 0x0401 || $cp === 0x0451) {
                ++$cyrillic;
            }
        }

        return ($printable / $len) + 0.25 * ($cyrillic / $len) - 2.0 * ($replacement / $len);
    }
}
