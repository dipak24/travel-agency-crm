<?php

namespace App\Models;

use Database\Factories\CountryFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

/**
 * Platform-wide country master data (ISO 3166-1), seeded from database/data/countries.php by
 * CountrySeeder. Every module that needs a country or nationality stores a `*_id` pointing here
 * instead of free text; dropdowns use Country::options() / App\Filament\Forms\Components\CountrySelect.
 */
class Country extends Model
{
    /** @use HasFactory<CountryFactory> */
    use HasFactory;

    public const OPTIONS_CACHE_KEY = 'countries.options';

    protected $fillable = ['name', 'iso2', 'iso3', 'phone_code', 'nationality', 'is_active'];

    protected static function booted(): void
    {
        static::saved(fn (): bool => Cache::forget(self::OPTIONS_CACHE_KEY));
        static::deleted(fn (): bool => Cache::forget(self::OPTIONS_CACHE_KEY));
    }

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    /**
     * @param  Builder<Country>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /**
     * Active countries keyed by id, labelled by either the country name or the nationality.
     *
     * @param  'name'|'nationality'  $label
     * @return array<int, string>
     */
    public static function options(string $label = 'name'): array
    {
        /** @var list<array{id: int, name: string, nationality: string}> $rows */
        $rows = Cache::rememberForever(self::OPTIONS_CACHE_KEY, fn (): array => static::query()
            ->active()
            ->orderBy('name')
            ->get(['id', 'name', 'nationality'])
            ->map(fn (Country $country): array => $country->only(['id', 'name', 'nationality']))
            ->all());

        return collect($rows)
            ->mapWithKeys(fn (array $row): array => [$row['id'] => $label === 'nationality'
                ? "{$row['nationality']} ({$row['name']})"
                : $row['name']])
            ->all();
    }
}
