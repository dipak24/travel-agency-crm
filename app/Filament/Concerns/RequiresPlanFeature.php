<?php

namespace App\Filament\Concerns;

use App\Support\PlanFeatures;

/**
 * For a staff panel or portal Resource/Page belonging to a module a subscription plan can switch
 * off: hides it from navigation and 403s direct URLs when the agency's plan disables the module.
 * The using class declares `planFeature()` returning a PlanFeatures constant.
 */
trait RequiresPlanFeature
{
    abstract protected static function planFeature(): string;

    public static function canAccess(): bool
    {
        return PlanFeatures::allowsCurrentTenant(static::planFeature()) && parent::canAccess();
    }
}
