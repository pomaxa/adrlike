<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Decision;
use App\Entity\User;
use App\Enum\Department;
use App\Enum\FollowUpStatus;
use App\Enum\Product;
use App\Repository\DecisionRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use League\Csv\Reader;

final class CsvImporter
{
    /** @var list<string> */
    public const CANDIDATE_ENCODINGS = [
        'UTF-8',
        'Windows-1251',
        'Windows-1252',
        'KOI8-R',
        'ISO-8859-1',
        'ISO-8859-5',
        'CP866',
    ];

    public const ENCODING_AUTO = 'auto';

    /** @var array<string, true> */
    private array $reservedEmails = [];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly DecisionRepository $decisions,
        private readonly UserRepository $users,
    ) {
    }

    public function import(string $rawBytes, string $encoding = self::ENCODING_AUTO, bool $dryRun = false): CsvImportResult
    {
        $this->reservedEmails = [];
        $result = new CsvImportResult();

        $raw = self::stripBom($rawBytes, $detectedBom);
        $sourceEncoding = strtolower($encoding) === self::ENCODING_AUTO
            ? ($detectedBom ?? self::detectEncoding($raw))
            : $encoding;
        $result->sourceEncoding = $sourceEncoding;

        if (strcasecmp($sourceEncoding, 'UTF-8') !== 0) {
            $utf8 = @mb_convert_encoding($raw, 'UTF-8', $sourceEncoding);
            if ($utf8 === false) {
                $result->fatalError = "Could not transcode from {$sourceEncoding} to UTF-8.";

                return $result;
            }
            $raw = $utf8;
        }

        if (!mb_check_encoding($raw, 'UTF-8')) {
            $result->fatalError = 'Input is not valid UTF-8 after conversion.';

            return $result;
        }

        try {
            $csv = Reader::fromString($raw);
            $csv->setHeaderOffset(1);
        } catch (\Throwable $e) {
            $result->fatalError = 'Could not parse CSV: ' . $e->getMessage();

            return $result;
        }

        $userCache = [];
        $rowNumber = 2;
        foreach ($csv->getRecords() as $row) {
            ++$rowNumber;

            $dateStr = trim((string) ($row['Date'] ?? ''));
            $change = trim((string) ($row['Change'] ?? ''));
            $submitterName = trim((string) ($row['Submitted by'] ?? ''));

            if ($dateStr === '' || $change === '' || $submitterName === '') {
                continue;
            }

            $decidedAt = \DateTimeImmutable::createFromFormat('!d.m.Y', $dateStr);
            if ($decidedAt === false) {
                $result->warnings[] = "Row {$rowNumber}: unparseable date '{$dateStr}' — skipped.";
                continue;
            }

            $importHash = self::hashRow($decidedAt, $submitterName, $change);
            if ($this->decisions->findOneByImportHash($importHash) !== null) {
                ++$result->skipped;
                continue;
            }

            $submittedBy = $this->resolveUser($submitterName, $userCache, $result);
            $approvedBy = self::nonEmpty($row['Approved by'] ?? null);
            $approvedByUser = $approvedBy !== null ? $this->resolveUser($approvedBy, $userCache, $result) : null;

            $followUpDateStr = trim((string) ($row['Follow-up date'] ?? ''));
            $followUpDate = null;
            if ($followUpDateStr !== '') {
                $followUpDate = \DateTimeImmutable::createFromFormat('!d.m.Y', $followUpDateStr) ?: null;
            }

            $decision = new Decision();
            $decision->setDecidedAt($decidedAt);
            $decision->setProduct(self::resolveProduct((string) ($row['Product'] ?? '')));
            $decision->setDepartment(self::resolveDepartment((string) ($row['Department'] ?? '')));
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
            ++$result->created;
        }

        if (!$dryRun) {
            $this->em->flush();
        } else {
            $this->em->clear();
        }

        return $result;
    }

    private function resolveUser(string $fullName, array &$cache, CsvImportResult $result): User
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
        ++$result->newUsers;

        return $cache[$key] = $user;
    }

    public static function stripBom(string $raw, ?string &$detectedBom): string
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

    public static function detectEncoding(string $raw): string
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
}
