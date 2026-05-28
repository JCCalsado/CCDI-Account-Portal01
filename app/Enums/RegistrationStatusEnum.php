<?php

namespace App\Enums;

enum RegistrationStatusEnum: string
{
    case PENDING          = 'pending';
    case APPROVED         = 'approved';
    case REJECTED         = 'rejected';
    case NEEDS_REVISION   = 'needs_revision';

    public function label(): string
    {
        return match ($this) {
            self::PENDING        => 'Pending Review',
            self::APPROVED       => 'Approved',
            self::REJECTED       => 'Rejected',
            self::NEEDS_REVISION => 'Needs Revision',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::PENDING        => 'yellow',
            self::APPROVED       => 'green',
            self::REJECTED       => 'red',
            self::NEEDS_REVISION => 'orange',
        };
    }

    public function isActionable(): bool
    {
        return in_array($this, [self::PENDING, self::NEEDS_REVISION], true);
    }
}