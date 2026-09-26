<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * A customer's portal account state. Staff-created customers start Pending and only become
 * Active once they open their emailed setup link and choose their own password.
 */
enum CustomerStatus: string implements HasColor, HasLabel
{
    case Pending = 'pending';
    case Active = 'active';
    case Suspended = 'suspended';
    case Inactive = 'inactive';

    public function getLabel(): string
    {
        return ucfirst($this->value);
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Pending => 'warning',
            self::Active => 'success',
            self::Suspended => 'danger',
            self::Inactive => 'gray',
        };
    }

    /**
     * Pending customers must still be able to reach the portal's password-reset page — finishing
     * it is exactly what activates them. A null password already blocks an actual login.
     */
    public function allowsPortalAccess(): bool
    {
        return $this === self::Pending || $this === self::Active;
    }
}
