<?php

namespace Database\Factories;

use App\Enums\PackageCategory;
use App\Models\Package;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Package>
 */
class PackageFactory extends Factory
{
    protected $model = Package::class;

    /**
     * A published trek priced per person. Tenant id comes from the ambient TenantContext.
     */
    public function definition(): array
    {
        $name = fake()->unique()->words(3, true).' Trek';

        return [
            'name' => $name,
            'slug' => Str::slug($name),
            'package_code' => strtoupper(fake()->unique()->bothify('PKG-####')),
            'description' => fake()->sentence(),
            'itinerary' => [],
            'base_price' => 80000,
            'sales_price' => 100000,
            'duration_days' => 12,
            'category' => PackageCategory::Trek,
            'min_pax' => 1,
            'is_public' => true,
            'status' => 'published',
        ];
    }
}
