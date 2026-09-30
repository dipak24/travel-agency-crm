@php
    use App\Support\Money;
    use App\Support\TenantContext;

    $currency = app(TenantContext::class)->get()->currency ?? 'USD';
@endphp
<x-public-site.layout title="Book your adventure">
    <h1>Book your adventure</h1>
    <p class="lead">Choose your trip to continue with the booking.</p>

    <form method="GET" action="{{ route('public.book.index') }}" class="card" style="max-width: 640px;">
        <h2 class="step"><span>1</span> Trip details</h2>

        @if ($packages->isEmpty())
            <p class="muted">No trips are open for booking right now. Please contact us.</p>
        @else
            <div class="field">
                <label for="trip">Select trip <span class="req">*</span></label>
                <select id="trip" name="trip" required onchange="if (this.value) this.form.submit()">
                    <option value="">Choose a trip</option>
                    @foreach ($packages as $package)
                        <option value="{{ $package->package_code }}">
                            {{ $package->name }} — {{ $package->duration_days }} days, from {{ Money::format($package->sales_price, $currency) }}
                        </option>
                    @endforeach
                </select>
            </div>
            <button type="submit" class="btn">Continue</button>
        @endif
    </form>
</x-public-site.layout>
