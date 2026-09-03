<?php
namespace App\Models;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
class Service extends Model
{
    use BelongsToTenant;
    protected $fillable = ['tenant_id', 'name', 'description', 'price', 'currency', 'is_active', 'has_limited_availability'];
    protected function casts(): array { return ['price' => 'integer', 'is_active' => 'boolean', 'has_limited_availability' => 'boolean']; }
    public function tenant(): BelongsTo { return $this->belongsTo(Tenant::class); }
    public function availability(): HasMany { return $this->hasMany(ServiceAvailability::class); }
}
