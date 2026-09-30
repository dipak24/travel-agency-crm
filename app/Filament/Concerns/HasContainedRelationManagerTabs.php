<?php

namespace App\Filament\Concerns;

use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Tabs;

/**
 * Filament\Resources\Pages\Concerns\HasRelationManagers::getRelationManagersContentComponent()
 * hardcodes ->contained(false) for a page's relation manager tabs, the same floating pill bar that
 * HasContainedTabs fixes on list pages. This flips only that option, so relation tabs render as
 * the app's left-aligned bar attached to the table below.
 */
trait HasContainedRelationManagerTabs
{
    public function getRelationManagersContentComponent(): Component
    {
        $component = parent::getRelationManagersContentComponent();

        return $component instanceof Tabs ? $component->contained(true) : $component;
    }
}
