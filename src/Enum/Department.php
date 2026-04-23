<?php

declare(strict_types=1);

namespace App\Enum;

enum Department: string
{
    case Risk = 'Risk';
    case Manual = 'Manual';
    case Other = 'Other';

    public function label(): string
    {
        return $this->value;
    }
}
