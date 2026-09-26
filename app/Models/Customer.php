<?php

namespace App\Models;

use App\Enums\ContactMethod;
use App\Enums\CustomerStatus;
use App\Models\Concerns\BelongsToTenant;
use App\Support\AgencySubdomain;
use Filament\Models\Contracts\FilamentUser;
use Filament\Models\Contracts\HasAvatar;
use Filament\Panel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Traits\HasRoles;

class Customer extends Authenticatable implements FilamentUser, HasAvatar
{
    use BelongsToTenant, HasFactory, HasRoles, Notifiable;

    /**
     * `status`, `email_verified_at`, `pending_email*` and `last_login_at` are deliberately not
     * fillable: they only change through the account flows (AccountSetupLinks activation,
     * CustomerEmailChange, the Login listener) or an explicit staff suspend/reactivate action.
     */
    protected $fillable = [
        'tenant_id', 'name', 'email', 'password', 'avatar', 'phone', 'whatsapp_number', 'whatsapp_same_as_mobile',
        'type', 'address', 'passport_no', 'date_of_birth', 'nationality_id', 'country_of_residence_id',
        'preferred_language', 'preferred_contact_method', 'emergency_contact_name', 'emergency_contact_phone',
        'emergency_contact_relationship', 'travel_interests', 'preferred_activity', 'dietary_preferences',
        'special_requirements', 'notes',
    ];

    protected $hidden = ['password', 'remember_token', 'passport_no'];

    /**
     * Mirrors the column default, so a freshly created model reads the right status without a refresh.
     *
     * @var array<string, mixed>
     */
    protected $attributes = ['status' => 'pending'];

    protected static function booted(): void
    {
        static::saving(function (Customer $customer): void {
            if ($customer->whatsapp_same_as_mobile) {
                $customer->whatsapp_number = $customer->phone;
            }
        });
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function nationality(): BelongsTo
    {
        return $this->belongsTo(Country::class, 'nationality_id');
    }

    public function countryOfResidence(): BelongsTo
    {
        return $this->belongsTo(Country::class, 'country_of_residence_id');
    }

    public function specialDates(): HasMany
    {
        return $this->hasMany(CustomerSpecialDate::class)->orderBy('date');
    }

    public function leads(): HasMany
    {
        return $this->hasMany(Lead::class);
    }

    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    public function isEmailVerified(): bool
    {
        return $this->email_verified_at !== null;
    }

    /**
     * Called once the customer has chosen their own password through an emailed link — opening
     * that link proves they own the address, so it doubles as email verification.
     */
    public function markAccountActivated(): void
    {
        $this->forceFill([
            'email_verified_at' => $this->email_verified_at ?? now(),
            'status' => $this->status === CustomerStatus::Pending ? CustomerStatus::Active : $this->status,
        ])->save();
    }

    public function canAccessPanel(Panel $panel): bool
    {
        // Deliberately does NOT also check `$this->password !== null` here.
        // Filament's own password-reset page re-checks canAccessPanel()
        // *before* saving the new password, using the model's current
        // (still-null, pre-reset) state — so gating login on a non-null
        // password here would make it impossible for an invited customer to
        // ever complete their first password setup. A null password already
        // blocks login on its own, since no submitted password can hash-match
        // a null stored value.
        //
        // On an agency subdomain, only that agency's own customers get in.
        $agency = app(AgencySubdomain::class)->get();

        return $panel->getId() === 'portal'
            && $this->status->allowsPortalAccess()
            && ($agency === null || $agency->getKey() === $this->tenant_id)
            && $this->tenant?->status !== 'suspended';
    }

    public function getFilamentAvatarUrl(): ?string
    {
        return $this->avatar ? Storage::disk('public')->url($this->avatar) : null;
    }

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'pending_email_requested_at' => 'datetime',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
            'passport_no' => 'encrypted',
            'status' => CustomerStatus::class,
            'date_of_birth' => 'date',
            'whatsapp_same_as_mobile' => 'boolean',
            'preferred_contact_method' => ContactMethod::class,
            'travel_interests' => 'array',
        ];
    }
}
