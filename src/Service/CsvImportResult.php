<?php

declare(strict_types=1);

namespace App\Service;

final class CsvImportResult
{
    public int $created = 0;
    public int $skipped = 0;
    public int $newUsers = 0;
    public string $sourceEncoding = 'UTF-8';
    /** @var list<string> */
    public array $warnings = [];
    public ?string $fatalError = null;

    public function isOk(): bool
    {
        return $this->fatalError === null;
    }
}
