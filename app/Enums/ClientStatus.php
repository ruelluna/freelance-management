<?php

namespace App\Enums;

enum ClientStatus: string
{
    case Active = 'active';
    case Archived = 'archived';

    public function label(): string
    {
        return match ($this) {
            self::Active => __('Active'),
            self::Archived => __('Archived'),
        };
    }
}
