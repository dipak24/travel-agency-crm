<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * The platform's own top-of-funnel: travel agencies interested in the product, not a tenant's leads.
 */
class SaasLead extends Model
{
    protected $fillable = ['name', 'email', 'company', 'status', 'source', 'notes'];
}
