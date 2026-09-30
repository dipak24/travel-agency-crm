<?php

namespace App\Models;

use App\Enums\PricingUnit;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Service extends Model
{
    use BelongsToTenant, HasFactory;

    protected $fillable = ['tenant_id', 'name', 'description', 'price', 'currency', 'pricing_unit', 'category', 'is_active'];

    protected function casts(): array
    {
        return [
            'price' => 'integer',
            'is_active' => 'boolean',
            'pricing_unit' => PricingUnit::class,
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function packages(): BelongsToMany
    {
        return $this->belongsToMany(Package::class)->withPivot(['price', 'is_featured']);
    }

    /**
     * The unit price of this service when sold with the given package: the package's own price
     * when the package offers it, otherwise the service's price.
     */
    public function unitPriceFor(?int $packageId): int
    {
        if ($packageId !== null) {
            $packagePrice = PackageService::query()
                ->where('package_id', $packageId)
                ->where('service_id', $this->getKey())
                ->value('price');

            if ($packagePrice !== null) {
                return (int) $packagePrice;
            }
        }

        return $this->price;
    }
}
