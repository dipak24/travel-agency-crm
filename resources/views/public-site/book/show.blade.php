@php
    use App\Support\Money;
    use App\Support\TenantContext;

    $currency = app(TenantContext::class)->get()->currency ?? 'USD';
@endphp
<x-public-site.layout :title="'Book '.$package->name">
    <h1>Book your adventure</h1>
    <p class="lead">
        {{ $package->name }} · <span class="pill">{{ $package->duration_days }} days</span>
        · From <strong>{{ Money::format($package->sales_price, $currency) }}</strong> per person
    </p>

    @include('public-site.partials.booking-form', [
        'action' => route('public.book.store', $package->package_code),
        'departure' => null,
        'minPax' => 1,
        'maxPax' => $maxPax,
        'currency' => $currency,
        'prefill' => $prefill,
    ])
</x-public-site.layout>
