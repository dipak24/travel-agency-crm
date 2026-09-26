<?php

namespace App\Models;

use App\Enums\CustomerSpecialDateType;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A customer-specific occasion (wedding anniversary, travel anniversary, anything custom) the
 * agency may want to greet the customer on. Birthdays come from Customer::$date_of_birth.
 */
class CustomerSpecialDate extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id', 'customer_id', 'type', 'label', 'date', 'remind', 'remind_days_before'];

    protected function casts(): array
    {
        return [
            'type' => CustomerSpecialDateType::class,
            'date' => 'date',
            'remind' => 'boolean',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
