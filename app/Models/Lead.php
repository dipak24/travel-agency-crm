<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Lead extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id', 'customer_id', 'source', 'origin', 'destination', 'trip_type',
        'pax_count', 'budget_range', 'status', 'assigned_staff_id',
        'follow_up_date', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'pax_count' => 'integer',
            'follow_up_date' => 'date',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function assignedStaff(): BelongsTo
    {
        return $this->belongsTo(TenantUser::class, 'assigned_staff_id');
    }
}
