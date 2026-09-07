<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BookingIncludeExclude extends Model
{
    use BelongsToTenant;

    protected $table = 'booking_include_exclude';

    protected $fillable = [
        'tenant_id',
        'booking_id',
        'include_exclude_id',
        'type',
        'title',
        'description',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function includeExclude(): BelongsTo
    {
        return $this->belongsTo(IncludeExclude::class);
    }
}
