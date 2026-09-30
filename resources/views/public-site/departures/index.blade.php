@php
    use App\Support\Money;
    use App\Support\TenantContext;

    $currency = app(TenantContext::class)->get()->currency ?? 'USD';
@endphp
<x-public-site.layout title="Group departures">
    <h1>Group departures</h1>
    <p class="lead">Join a scheduled group trip — fixed dates, a shared guide and great company.</p>

    @if ($departures->isEmpty())
        <div class="card">There are no upcoming group departures right now — please check back soon.</div>
    @else
        @foreach ($departures->groupBy(fn ($departure) => $departure->start_date->format('F Y')) as $month => $monthDepartures)
            <h2 style="margin-top: 22px;">{{ $month }}</h2>
            <div class="card" style="padding: 8px 18px;">
                <table>
                    <thead><tr><th>Trip</th><th>Dates</th><th>Price per person</th><th>Seats</th><th></th></tr></thead>
                    <tbody>
                        @foreach ($monthDepartures as $departure)
                            @php $seats = $departure->publicSeatsRemaining(); @endphp
                            <tr>
                                <td><strong>{{ $departure->package->name }}</strong></td>
                                <td>
                                    {{ $departure->start_date->format('D j M') }} – {{ $departure->end_date->format('D j M Y') }}
                                    <div class="muted">{{ $departure->start_date->diffInDays($departure->end_date) + 1 }} days</div>
                                </td>
                                <td>{{ Money::format($departure->perPersonPrice(), $currency) }}</td>
                                <td>{{ $seats > 0 ? "{$seats} left" : 'Full' }}</td>
                                <td style="text-align: right;">
                                    @if ($seats > 0)
                                        <a class="btn" href="{{ route('public.departures.show', $departure->id) }}">Join group</a>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endforeach
    @endif
</x-public-site.layout>
