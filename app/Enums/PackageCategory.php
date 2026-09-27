<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum PackageCategory: string implements HasLabel
{
    case Trek = 'trek';
    case Tour = 'tour';
    case DayTour = 'day_tour';
    case Activity = 'activity';

    public function getLabel(): string
    {
        return match ($this) {
            self::Trek => 'Trek',
            self::Tour => 'Tour',
            self::DayTour => 'Day tour',
            self::Activity => 'Activity',
        };
    }
}
