<?php

namespace App\Filament\Concerns;

use Filament\Support\Enums\Width;

/**
 * Stretches a resource's Create/Edit/View page to the panel's full content
 * width instead of Filament's default centered/narrow form width.
 *
 * Overrides the getter rather than the `$maxContentWidth` property directly:
 * a trait redeclaring a property already inherited from the class's parent
 * with a different default value is a PHP fatal error ("incompatible"
 * property composition), even though a plain subclass property override is
 * fine. Overriding the method both sidesteps that and matches how BasePage
 * itself exposes the value.
 */
trait HasFullWidthForm
{
    public function getMaxContentWidth(): Width|string|null
    {
        return Width::Full;
    }
}
