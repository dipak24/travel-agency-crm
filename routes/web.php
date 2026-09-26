<?php

use App\Http\Controllers\CustomerEmailChangeController;
use App\Http\Controllers\Payments\CheckoutController;
use App\Http\Controllers\Payments\PublicPaymentController;
use App\Http\Controllers\Payments\WebhookController;
use App\Http\Controllers\UnsubscribeController;
use App\Http\Middleware\ResolveAgencySubdomain;
use App\Http\Middleware\ResolveTenant;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::middleware([ResolveAgencySubdomain::class, 'auth:customer', ResolveTenant::class])
    ->prefix('portal/pay')
    ->name('payments.')
    ->group(function (): void {
        Route::get('/{gateway}/{invoice}/start', [CheckoutController::class, 'start'])->name('checkout')->middleware('throttle:20,1');
        Route::get('/{gateway}/{invoice}/return', [CheckoutController::class, 'return'])->name('return');
    });

Route::prefix('pay')->name('public.pay.')->group(function (): void {
    Route::get('/{invoice}', [PublicPaymentController::class, 'show'])->name('show')->middleware('signed');
    Route::get('/{gateway}/{invoice}/start', [PublicPaymentController::class, 'start'])->name('start')->middleware(['signed', 'throttle:20,1']);
    Route::get('/{gateway}/{invoice}/return', [PublicPaymentController::class, 'return'])->name('return');
});

Route::post('/webhooks/{gateway}/{invoice}', [WebhookController::class, 'handle'])->name('payments.webhook');

Route::middleware(['signed', 'throttle:20,1'])->group(function (): void {
    Route::get('/unsubscribe', [UnsubscribeController::class, 'show'])->name('email.unsubscribe');
    Route::post('/unsubscribe', [UnsubscribeController::class, 'store'])->name('email.unsubscribe.store');
});

Route::middleware([ResolveAgencySubdomain::class, 'signed', 'throttle:6,1'])->prefix('portal/email-change')->name('portal.email-change.')->group(function (): void {
    Route::get('/{customer}/{hash}', [CustomerEmailChangeController::class, 'show'])->name('verify')->whereNumber('customer');
    Route::post('/{customer}/{hash}', [CustomerEmailChangeController::class, 'store'])->name('confirm')->whereNumber('customer');
});
