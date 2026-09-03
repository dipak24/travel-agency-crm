<?php
namespace App\Models;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;
class ServiceAvailability extends Model
{
    use BelongsToTenant;

    protected static function booted(): void
    {
        static::saving(function (ServiceAvailability $availability): void {
            if (! Service::query()->whereKey($availability->service_id)->exists()) {
                throw new LogicException('The service must belong to the current tenant.');
            }
        });
    }
    protected $table = 'service_availability';
    protected $fillable = ['tenant_id', 'service_id', 'date', 'total_slots', 'booked_slots'];
    protected function casts(): array { return ['date' => 'date', 'total_slots' => 'integer', 'booked_slots' => 'integer']; }
    public function tenant(): BelongsTo { return $this->belongsTo(Tenant::class); }
    public function service(): BelongsTo { return $this->belongsTo(Service::class); }
    public function remainingSlots(): int { return max(0, $this->total_slots - $this->booked_slots); }
}
