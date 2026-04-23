<?php

declare(strict_types=1);

namespace App\Enum;

enum FollowUpStatus: string
{
    case NotRequired = 'not_required';
    case Pending = 'pending';
    case Overdue = 'overdue';
    case Done = 'done';

    public function label(): string
    {
        return match ($this) {
            self::NotRequired => 'Not required',
            self::Pending => 'Pending',
            self::Overdue => 'Overdue',
            self::Done => 'Done',
        };
    }

    public function badgeClass(): string
    {
        return match ($this) {
            self::NotRequired => 'bg-secondary',
            self::Pending => 'bg-info',
            self::Overdue => 'bg-danger',
            self::Done => 'bg-success',
        };
    }
}
