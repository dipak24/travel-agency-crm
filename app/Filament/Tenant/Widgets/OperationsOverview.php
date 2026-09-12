<?php

namespace App\Filament\Tenant\Widgets;

use App\Models\Booking;
use App\Models\Invoice;
use App\Models\Lead;
use App\Models\Payment;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class OperationsOverview extends BaseWidget
{
    protected static bool $isLazy = false;

    protected static ?int $sort = 1;

    protected function getStats(): array
    {
        $tenant = auth('tenant')->user()->tenant;
        $currency = $tenant?->currency ?? 'USD';

        $bookingsThisMonth = Booking::query()
            ->whereMonth('created_at', now()->month)
            ->whereYear('created_at', now()->year)
            ->count();

        $pendingInvoices = Invoice::query()->whereIn('status', ['issued', 'partially_paid', 'overdue']);
        $pendingCount = (clone $pendingInvoices)->count();
        $pendingInvoiceIds = (clone $pendingInvoices)->pluck('id');
        $pendingTotal = (clone $pendingInvoices)->sum('total');
        $received = Payment::query()->whereIn('invoice_id', $pendingInvoiceIds)->where('status', 'completed')->where('type', '!=', 'refund')->sum('amount');
        $refunded = Payment::query()->whereIn('invoice_id', $pendingInvoiceIds)->where('status', 'completed')->where('type', 'refund')->sum('amount');
        $outstanding = max(0, $pendingTotal - ($received - $refunded));

        $upcomingTrips = Booking::query()
            ->whereBetween('start_date', [now()->startOfDay(), now()->addDays(30)])
            ->whereNotIn('status', ['cancelled'])
            ->count();

        $openLeads = Lead::query()->whereIn('status', ['new', 'contacted', 'negotiating'])->count();

        return [
            Stat::make('Bookings this month', $bookingsThisMonth),
            Stat::make('Pending invoices', $pendingCount)
                ->description($currency.' '.number_format($outstanding / 100, 2).' outstanding')
                ->color($pendingCount > 0 ? 'warning' : 'success'),
            Stat::make('Upcoming trips (30 days)', $upcomingTrips),
            Stat::make('Open leads', $openLeads),
        ];
    }
}
