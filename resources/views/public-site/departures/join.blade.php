@php
    use App\Support\Money;
    use App\Support\TenantContext;

    $currency = app(TenantContext::class)->get()->currency ?? 'USD';
    $seats = $departure->publicSeatsRemaining();
@endphp
<x-public-site.layout :title="'Join '.$package->name">
    <p class="muted" style="margin: 0 0 6px;"><a href="{{ route('public.departures.index') }}">← All group departures</a></p>
    <h1>Book your adventure</h1>
    <p class="lead">
        {{ $package->name }} · Group departure {{ $departure->start_date->format('D, j M Y') }} – {{ $departure->end_date->format('D, j M Y') }}
        · <strong>{{ Money::format($departure->perPersonPrice(), $currency) }}</strong> per person
        · {{ $seats > 0 ? "{$seats} seats left" : 'Full' }}
    </p>

    @if ($seats > 0)
        @include('public-site.partials.booking-form', [
            'action' => route('public.departures.join', $departure->id),
            'minPax' => 1,
            'maxPax' => $seats,
            'currency' => $currency,
            'prefill' => $prefill,
        ])
    @else
        <div class="card">
            <h2>This departure is full</h2>
            <p class="muted">Please choose another date or contact us to join the waiting list.</p>
        </div>
    @endif
</x-public-site.layout>
