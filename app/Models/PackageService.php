<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * An add-on service offered with a package (e.g. skydiving in Pokhara on an Everest trek), with an
 * optional package-specific price. The same service can still be booked on its own.
 */
class PackageService extends Model
{
    use BelongsToTenant;

    protected $table = 'package_service';

    protected static function booted(): void
    {
        static::saving(function (self $link): void {
            if (! Package::query()->whereKey($link->package_id)->exists()
                || ! Service::query()->whereKey($link->service_id)->exists()) {
                throw new LogicException('The package and service must belong to the current tenant.');
            }
        });
    }

    protected $fillable = ['tenant_id', 'package_id', 'service_id', 'price_override', 'is_featured'];

    protected function casts(): array
    {
        return [
            'price_override' => 'integer',
            'is_featured' => 'boolean',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function package(): BelongsTo
    {
        return $this->belongsTo(Package::class);
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }
}
