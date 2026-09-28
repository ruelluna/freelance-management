<?php

namespace App\Enums;

enum Provider: string
{
    case Github = 'github';
    case Todoist = 'todoist';
    case Superhuman = 'superhuman';

    public function label(): string
    {
        return match ($this) {
            self::Github => 'GitHub',
            self::Todoist => 'Todoist',
            self::Superhuman => 'Superhuman Docs',
        };
    }

    public function isAvailable(): bool
    {
        return $this === self::Github;
    }
}
