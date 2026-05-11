<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use App\Enums\UserRoleEnum;

class User extends Authenticatable
{
    use HasFactory, Notifiable;

    // Status constants
    const STATUS_ACTIVE    = 'active';
    const STATUS_GRADUATED = 'graduated';
    const STATUS_DROPPED   = 'dropped';

    protected $fillable = [
        'last_name',
        'first_name',
        'middle_initial',
        'email',
        'password',
        'birthday',
        'address_house_lot_unit',
        'address_street_name',
        'address_barangay',
        'address_municipality_city',
        'address_province',
        'phone',
        'account_id',
        'profile_picture',
        'course',
        'year_level',
        'is_irregular',
        'faculty',
        'status',
        'role',
        'is_active',
        'permissions',
        'department',
        'created_by',
        'updated_by',
        'last_login_at',
    ];

    /**
     * Boot method to protect audit immutability:
     * Once created_by is set, it cannot be changed via mass-assignment update().
     */
    protected static function boot(): void
    {
        parent::boot();

        static::updating(function (self $user) {
            // Prevent created_by from being changed once set
            if ($user->isDirty('created_by') && $user->getOriginal('created_by') !== null) {
                $user->created_by = $user->getOriginal('created_by');
            }
        });
    }

    protected $hidden = [
        'password',
        'remember_token',
    ];

    // Set the appends property to include virtual attributes
    protected $appends = ['name'];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password'          => 'hashed',
            'role'              => UserRoleEnum::class,
            'birthday'          => 'date',
            'terms_accepted_at' => 'datetime',
            'permissions'       => 'json',
            'is_active'         => 'boolean',
            'is_irregular'      => 'boolean',
            'last_login_at'     => 'datetime',
        ];
    }

    // ========== RELATIONSHIPS ==========

    public function student(): HasOne
    {
        return $this->hasOne(Student::class);
    }

    public function account(): HasOne
    {
        return $this->hasOne(Account::class);
    }

    public function latestAssessment(): HasOne
    {
        return $this->hasOne(\App\Models\StudentAssessment::class, 'user_id')
                    ->where('status', 'active')
                    ->latestOfMany();
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    // Admin audit relationships
    public function createdByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updatedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    // ========== ACCESSORS ==========

    /**
     * Get the user's full name.
     * This is the main accessor that will be serialized in API responses.
     */
    public function getNameAttribute(): string
    {
        $mi = $this->middle_initial ? ' ' . strtoupper($this->middle_initial) . '.' : '';
        return "{$this->last_name}, {$this->first_name}{$mi}";
    }

    /**
     * Get the user's full name (alternative format).
     * Use this for display purposes where you want "Last, First MI."
     */
    public function getFullNameAttribute(): string
    {
        $mi = $this->middle_initial ? "{$this->middle_initial}." : '';
        return "{$this->last_name}, {$this->first_name} {$mi}";
    }

    // ========== SCOPES ==========

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeAdmins($query)
    {
        return $query->where('role', UserRoleEnum::ADMIN->value);
    }

    public function scopeStudents($query)
    {
        return $query->where('role', UserRoleEnum::STUDENT->value);
    }

    public function scopeAccounting($query)
    {
        return $query->where('role', UserRoleEnum::ACCOUNTING->value);
    }

    public function scopeTermsAccepted($query)
    {
        return $query->whereNotNull('terms_accepted_at');
    }

    // ========== ADMIN HELPERS ==========

    /**
     * Check if user is an admin
     */
    public function isAdmin(): bool
    {
        return $this->role === UserRoleEnum::ADMIN;
    }

    public function isAccounting(): bool
    {
        return $this->role === UserRoleEnum::ACCOUNTING;
    }

    /**
     * Check if user has accepted terms & conditions
     */
    public function hasAcceptedTerms(): bool
    {
        return $this->terms_accepted_at !== null;
    }

    /**
     * Accept terms & conditions
     */
    public function acceptTerms(): void
    {
        $this->forceFill(['terms_accepted_at' => now()])->save();
    }

    /**
     * Check if user has specific permission
     */
    public function hasPermission(string $permission): bool
    {
        // Inactive users have no permissions regardless of role
        if (! $this->is_active) {
            return false;
        }

        // All admins have all permissions
        if ($this->isAdmin()) {
            return true;
        }

        return false;
    }

    /**
     * Check multiple permissions (OR logic)
     */
    public function hasAnyPermission(array $permissions): bool
    {
        foreach ($permissions as $permission) {
            if ($this->hasPermission($permission)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Check multiple permissions (AND logic)
     */
    public function hasAllPermissions(array $permissions): bool
    {
        foreach ($permissions as $permission) {
            if (! $this->hasPermission($permission)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Update user activity timestamp
     */
    public function recordLastLogin(): void
    {
        $this->update(['last_login_at' => now()]);
    }

    // ========== VALIDATION RULES ==========

    /**
     * Get validation rules for user updates.
     * NOTE: profile_picture mimes must stay in sync with
     * Settings/ProfileController::updatePicture() validation.
     */
    public static function getValidationRules($userId = null): array
    {
        return [
            'account_id'               => 'nullable|string|unique:users,account_id,' . $userId,
            'address_house_lot_unit'   => 'nullable|string|max:255',
            'address_street_name'      => 'nullable|string|max:255',
            'address_barangay'         => 'nullable|string|max:255',
            'address_municipality_city'=> 'nullable|string|max:255',
            'address_province'         => 'nullable|string|max:255',
            'phone'                    => 'nullable|string|max:20',
            'course'                   => 'nullable|string|max:100',
            'year_level'               => 'nullable|string|max:50',
            'faculty'                  => 'nullable|string|max:100',
            'status'                   => 'required|in:active,graduated,dropped',
            // webp included — must match ProfileController::updatePicture() mimes rule
            'profile_picture'          => 'nullable|image|mimes:jpeg,png,jpg,gif,webp|max:2048',
        ];
    }

    /**
     * Get validation rules for admin creation/update
     */
    public static function getAdminValidationRules($userId = null): array
    {
        $uniqueEmail = $userId ? "unique:users,email,{$userId}" : 'unique:users,email';

        return [
            'last_name'      => 'required|string|max:100',
            'first_name'     => 'required|string|max:100',
            'middle_initial' => 'nullable|string|max:1',
            'email'          => "required|email|{$uniqueEmail}",
            'password'       => $userId ? 'nullable|min:8|confirmed' : 'required|min:8|confirmed',
            'department'     => 'required|in:Administrator,Accounting',
            'is_active'      => 'boolean',
        ];
    }
}