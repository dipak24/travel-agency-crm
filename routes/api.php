<?php

use App\Http\Controllers\Api\V1\DepartureController;
use App\Http\Controllers\Api\V1\InquiryController;
use App\Http\Controllers\Api\V1\PackageController;
use App\Http\Controllers\Api\V1\WaitlistController;
use App\Http\Middleware\ResolvePublicTenant;
use Illuminate\Support\Facades\Route;

/*
| Public, unauthenticated tenant website API. Throttling runs before tenant resolution so that
| probing for tenant slugs is rate limited too.
*/
Route::prefix('v1')->name('api.v1.')->group(function (): void {
    Route::middleware(['throttle:public-api', ResolvePublicTenant::class])->group(function (): void {
        Route::get('/packages', [PackageController::class, 'index'])->name('packages.index');
        Route::get('/packages/{slug}', [PackageController::class, 'show'])->name('packages.show');
        Route::get('/departures', [DepartureController::class, 'index'])->name('departures.index');
    });

    Route::middleware(['throttle:public-api-submissions', ResolvePublicTenant::class])->group(function (): void {
        Route::post('/inquiries', [InquiryController::class, 'store'])->name('inquiries.store');
        Route::post('/departures/{departure}/waitlist', [WaitlistController::class, 'store'])
            ->whereNumber('departure')
            ->name('departures.waitlist.store');
    });
});
