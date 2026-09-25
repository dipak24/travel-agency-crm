<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A platform-defined kind of transactional email (booking status change, payment reminder, ...).
 * Rows are synced from App\Support\TransactionalEmailTypes by App\Services\Mail\EmailTemplates —
 * never edited by hand.
 */
class EmailTemplateType extends Model
{
    protected $fillable = [
        'key', 'name', 'description', 'default_subject', 'default_body_html', 'available_merge_tags',
    ];

    protected function casts(): array
    {
        return ['available_merge_tags' => 'array'];
    }

    public function templates(): HasMany
    {
        return $this->hasMany(EmailTemplate::class);
    }
}
