<?php

namespace App\Models;

use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\Models\Activity as SpatieActivity;

/**
 * The activity log entry model (registered as `activitylog.activity_model`). Adds `tenant_id`,
 * which decides which portal's Audit Log an entry belongs to: taken from the logged record itself when it's
 * tenant-owned, else from the acting user, else from the ambient TenantContext. Stays null for
 * platform-level activity.
 *
 * Entries older than RETENTION_MONTHS are pruned daily (`model:prune`, see routes/console.php),
 * except the most recent entry for each record, which is always kept.
 *
 * Not BelongsToTenant on purpose — the admin Audit Log reads platform entries (null tenant_id),
 * which a tenant scope would hide; each Audit Log resource and ActivityPolicy scope it instead.
 */
class Activity extends SpatieActivity
{
    use MassPrunable;

    public const RETENTION_MONTHS = 3;

    protected static function booted(): void
    {
        static::creating(function (Activity $activity): void {
            $activity->tenant_id ??= static::tenantIdFrom($activity, 'subject')
                ?? static::tenantIdFrom($activity, 'causer')
                ?? app(TenantContext::class)->id();
        });
    }

    /**
     * Old entries, minus each record's latest entry (highest id per subject).
     */
    public function prunable(): Builder
    {
        return static::query()
            ->where('created_at', '<', now()->subMonths(self::RETENTION_MONTHS))
            ->whereNotIn('id', static::query()
                ->whereNotNull('subject_type')
                ->selectRaw('max(id)')
                ->groupBy('subject_type', 'subject_id'));
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * Reads tenant_id off the subject/causer model the logger attached — never lazily loads it,
     * since a tenant-scoped lazy load outside a tenant context would silently find nothing.
     */
    private static function tenantIdFrom(Activity $activity, string $relation): ?int
    {
        $model = $activity->relationLoaded($relation) ? $activity->getRelation($relation) : null;
        $tenantId = $model instanceof Model ? $model->getAttribute('tenant_id') : null;

        return $tenantId === null ? null : (int) $tenantId;
    }
}
