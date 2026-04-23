<?php

declare(strict_types=1);

namespace App\Enum;

enum Product: string
{
    case Leasing = 'Leasing';
    case Installment = 'Installment';
    case Leaseback = 'Leaseback';
    case AllProduct = 'All Product';

    public function label(): string
    {
        return $this->value;
    }
}
