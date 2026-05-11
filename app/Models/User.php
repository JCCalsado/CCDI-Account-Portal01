<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use App\Enums\UserRoleEnum;

class User extends Authenticatable
{
    use HasFactory, Notifiable;

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

    protected static function boot(): void
    {
        parent::boot();

        static::updating(function (self $user) {
            if ($user->isDirty('created_by') && $user->getOriginal('created_by') !== null) {
                $user->created_by = $user->getOriginal('created_by');
            }
        });
    }

    protected $hidden = [
        'password',
        'remember_token',
    ];

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

    /**
     * All assessments belonging to this user.
     * student_assessments.user_id is a direct FK to users.id.
     */
    public function assessments(): HasMany
    {
        return $this->hasMany(StudentAssessment::class, 'user_id');
    }

    /**
     * The single most recent active assessment.
     * Use this for display; use assessments() for querying across all.
     */
    public function latestAssessment(): HasOne
    {
        return $this->hasOne(StudentAssessment::class, 'user_id')
                    ->where('status', 'active')
                    ->latestOfMany();
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    public function createdByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updatedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    // ========== ACCESSORS ==========

    public function getNameAttribute(): string
    {
        $mi = $this->middle_initial ? ' ' . strtoupper($this->middle_initial) . '.' : '';
        return "{$this->last_name}, {$this->first_name}{$mi}";
    }

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

    // ========== HELPERS ==========

    public function isAdmin(): bool
    {
        return $this->role === UserRoleEnum::ADMIN;
    }

    public function isAccounting(): bool
    {
        return $this->role === UserRoleEnum::ACCOUNTING;
    }

    public function hasAcceptedTerms(): bool
    {
        return $this->terms_accepted_at !== null;
    }

    public function acceptTerms(): void
    {
        $this->forceFill(['terms_accepted_at' => now()])->save();
    }

    public function hasPermission(string $permission): bool
    {
        if (! $this->is_active) {
            return false;
        }

        if ($this->isAdmin()) {
            return true;
        }

        return false;
    }

    public function hasAnyPermission(array $permissions): bool
    {
        foreach ($permissions as $permission) {
            if ($this->hasPermission($permission)) {
                return true;
            }
        }
        return false;
    }

    public function hasAllPermissions(array $permissions): bool
    {
        foreach ($permissions as $permission) {
            if (! $this->hasPermission($permission)) {
                return false;
            }
        }
        return true;
    }

    public function recordLastLogin(): void
    {
        $this->update(['last_login_at' => now()]);
    }

    // ========== VALIDATION RULES ==========

    public static function getValidationRules($userId = null): array
    {
        return [
            'account_id'                => 'nullable|string|unique:users,account_id,' . $userId,
            'address_house_lot_unit'    => 'nullable|string|max:255',
            'address_street_name'       => 'nullable|string|max:255',
            'address_barangay'          => 'nullable|string|max:255',
            'address_municipality_city' => 'nullable|string|max:255',
            'address_province'          => 'nullable|string|max:255',
            'phone'                     => 'nullable|string|max:20',
            'course'                    => 'nullable|string|max:100',
            'year_level'                => 'nullable|string|max:50',
            'faculty'                   => 'nullable|string|max:100',
            'status'                    => 'required|in:active,graduated,dropped',
            'profile_picture'           => 'nullable|image|mimes:jpeg,png,jpg,gif,webp|max:2048',
        ];
    }

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