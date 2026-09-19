<?php

namespace App\Filament\Concerns;

use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Tabs;

/**
 * Filament\Resources\Concerns\HasTabs::getTabsContentComponent() hardcodes ->contained(false),
 * which renders a ListRecords page's tabs as a centered, floating pill bar detached from the
 * table below it. Overriding just this one method (rather than fighting it with CSS) reuses the
 * exact same tab set/state Filament already built and only flips the one option that matters:
 * `contained(true)` renders the tabs as a left-aligned, bottom-bordered bar that sits flush
 * against the table's own card instead of floating above it.
 */
trait HasContainedTabs
{
    public function getTabsContentComponent(): Component
    {
        return Tabs::make()
            ->key('resourceTabs')
            ->livewireProperty('activeTab')
            ->contained(true)
            ->tabs($this->getCachedTabs())
            ->hidden(empty($this->getCachedTabs()));
    }
}
