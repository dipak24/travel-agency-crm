<?php

use App\Filament\Concerns\HasContainedTabs;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Str;
use Symfony\Component\Finder\Finder;

/**
 * Every tabbed list page in every panel must render its tabs the same way — the contained,
 * left-aligned bar attached to the table (see HasContainedTabs) — not Filament's default floating
 * pill bar. The portal's "My Bookings" page was the one page missing it.
 */
test('every list page with tabs uses the contained tab design', function () {
    $tabbedPages = collect(Finder::create()->files()->in(app_path('Filament'))->name('List*.php'))
        ->map(fn (SplFileInfo $file): string => 'App\\'.Str::of($file->getRealPath())
            ->after(app_path().DIRECTORY_SEPARATOR)
            ->beforeLast('.php')
            ->replace(DIRECTORY_SEPARATOR, '\\'))
        ->filter(fn (string $class): bool => is_subclass_of($class, ListRecords::class)
            && (new ReflectionMethod($class, 'getTabs'))->getDeclaringClass()->getName() === $class)
        ->values();

    expect($tabbedPages)->not->toBeEmpty();

    foreach ($tabbedPages as $page) {
        expect(in_array(HasContainedTabs::class, class_uses_recursive($page), true))
            ->toBeTrue("{$page} defines tabs but doesn't use HasContainedTabs");
    }
});
