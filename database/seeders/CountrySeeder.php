<?php

namespace Database\Seeders;

use App\Models\Country;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Cache;

class CountrySeeder extends Seeder
{
    /**
     * Upserts the ISO country list from database/data/countries.php keyed on iso2, so re-running
     * it never duplicates rows and never changes an id that other tables already point to.
     */
    public function run(): void
    {
        /** @var list<array{0: string, 1: string, 2: string, 3: string, 4: string}> $countries */
        $countries = require database_path('data/countries.php');
        $now = now();

        Country::query()->upsert(
            array_map(fn (array $row): array => [
                'iso2' => $row[0],
                'iso3' => $row[1],
                'name' => $row[2],
                'phone_code' => $row[3],
                'nationality' => $row[4],
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ], $countries),
            ['iso2'],
            ['iso3', 'name', 'phone_code', 'nationality', 'updated_at'],
        );

        Cache::forget(Country::OPTIONS_CACHE_KEY);
    }
}
