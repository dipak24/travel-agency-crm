<?php

use App\Http\Controllers\CustomerEmailChangeController;
use App\Http\Controllers\ImpersonationController;
use App\Http\Controllers\Payments\CheckoutController;
use App\Http\Controllers\Payments\PostedReturnController;
use App\Http\Controllers\Payments\PublicPaymentController;
use App\Http\Controllers\Payments\WebhookController;
use App\Http\Controllers\PublicSite\DepartureController;
use App\Http\Controllers\PublicSite\DepartureWidgetController;
use App\Http\Controllers\PublicSite\GiftVoucherController;
use App\Http\Controllers\PublicSite\PackageBookingController;
use App\Http\Controllers\UnsubscribeController;
use App\Http\Middleware\EnsurePlatformAvailable;
use App\Http\Middleware\ResolveAgencySubdomain;
use App\Http\Middleware\ResolveTenant;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::middleware([EnsurePlatformAvailable::class, ResolveAgencySubdomain::class, 'auth:customer', ResolveTenant::class])
    ->prefix('portal/pay')
    ->name('payments.')
    ->group(function (): void {
        Route::get('/{gateway}/{invoice}/start', [CheckoutController::class, 'start'])->name('checkout')->middleware('throttle:20,1');
        Route::get('/{gateway}/{invoice}/return', [CheckoutController::class, 'return'])->name('return');
    });

Route::middleware(EnsurePlatformAvailable::class)->prefix('pay')->name('public.pay.')->group(function (): void {
    Route::get('/{invoice}', [PublicPaymentController::class, 'show'])->name('show')->middleware('signed');
    Route::get('/{gateway}/{invoice}/start', [PublicPaymentController::class, 'start'])->name('start')->middleware(['signed', 'throttle:20,1']);
    Route::get('/{gateway}/{invoice}/return', [PublicPaymentController::class, 'return'])->name('return');
});

Route::middleware([EnsurePlatformAvailable::class, 'throttle:30,1'])->group(function (): void {
    Route::post('/portal/pay/{gateway}/{invoice}/return', PostedReturnController::class)->name('payments.return.posted');
    Route::post('/pay/{gateway}/{invoice}/return', PostedReturnController::class)->name('public.pay.return.posted');
});

Route::post('/webhooks/{gateway}/{invoice}', [WebhookController::class, 'handle'])->name('payments.webhook');

Route::middleware(['signed', 'throttle:20,1'])->group(function (): void {
    Route::get('/unsubscribe', [UnsubscribeController::class, 'show'])->name('email.unsubscribe');
    Route::post('/unsubscribe', [UnsubscribeController::class, 'store'])->name('email.unsubscribe.store');
});

Route::middleware([ResolveAgencySubdomain::class])->prefix('impersonate')->name('impersonation.')->group(function (): void {
    Route::get('/{token}', [ImpersonationController::class, 'enter'])->name('enter')->middleware(['signed', 'throttle:10,1']);
    Route::post('/leave', [ImpersonationController::class, 'leave'])->name('leave');
});

Route::middleware([EnsurePlatformAvailable::class, ResolveAgencySubdomain::class, 'signed', 'throttle:6,1'])->prefix('portal/email-change')->name('portal.email-change.')->group(function (): void {
    Route::get('/{customer}/{hash}', [CustomerEmailChangeController::class, 'show'])->name('verify')->whereNumber('customer');
    Route::post('/{customer}/{hash}', [CustomerEmailChangeController::class, 'store'])->name('confirm')->whereNumber('customer');
});

// The agency's public booking pages on its own subdomain — no login. The agency's own marketing
// site links here (booking links, departures widget); trip content itself lives on that site. Each
// page 404s unless the agency has switched it on (tenant Website settings) and its plan allows it
// (see PublicSite).
Route::middleware([EnsurePlatformAvailable::class, ResolveAgencySubdomain::class])->name('public.')->group(function (): void {
    Route::get('/book', [PackageBookingController::class, 'index'])->name('book.index');
    Route::get('/book/{code}', [PackageBookingController::class, 'show'])->name('book.show');
    Route::post('/book/{code}/check-code', [PackageBookingController::class, 'checkCode'])->name('book.check-code')->middleware('throttle:10,1');
    Route::post('/book/{code}', [PackageBookingController::class, 'store'])->name('book.store')->middleware('throttle:10,1');

    Route::get('/widget/departures', DepartureWidgetController::class)->name('widget.departures')->middleware('throttle:60,1');

    Route::get('/departures', [DepartureController::class, 'index'])->name('departures.index');
    Route::get('/departures/{departure}/join', [DepartureController::class, 'show'])->name('departures.show')->whereNumber('departure');
    Route::post('/departures/{departure}/join', [DepartureController::class, 'join'])->name('departures.join')->whereNumber('departure')->middleware('throttle:10,1');

    Route::get('/gift-vouchers', [GiftVoucherController::class, 'create'])->name('gift-vouchers.create');
    Route::post('/gift-vouchers', [GiftVoucherController::class, 'store'])->name('gift-vouchers.store')->middleware('throttle:10,1');
});
