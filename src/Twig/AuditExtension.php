<?php

declare(strict_types=1);

namespace App\Twig;

use App\Entity\DecisionHistory;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;
use Twig\TwigFunction;

final class AuditExtension extends AbstractExtension
{
    public function getFunctions(): array
    {
        return [
            new TwigFunction('decision_history_label', DecisionHistory::labelFor(...)),
        ];
    }

    public function getFilters(): array
    {
        return [
            new TwigFilter('audit_truncate', self::truncate(...)),
        ];
    }

    public static function truncate(?string $value, int $length = 160, string $suffix = '…'): string
    {
        if ($value === null || $value === '') {
            return '';
        }
        if (mb_strlen($value) <= $length) {
            return $value;
        }

        return mb_substr($value, 0, $length) . $suffix;
    }
}
