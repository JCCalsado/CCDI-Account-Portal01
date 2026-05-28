<?php

namespace App\Models;

use App\Enums\RegistrationStatusEnum;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class StudentRegistration extends Model
{
    protected $fillable = [
        'tracking_token',
        'last_name',
        'first_name',
        'middle_name',
        'suffix',
        'gender',
        'birthdate',
        'civil_status',
        'contact_number',
        'email',
        'address_house',
        'address_street',
        'address_barangay',
        'address_city',
        'address_province',
        'address_zip',
        'existing_student_id',
        'course',
        'year_level',
        'semester',
        'school_year',
        'student_type',
        'guardian_name',
        'guardian_contact',
        'emergency_contact',
        'valid_id_path',
        'proof_of_enrollment_path',
        'status',
        'rejection_reason',
        'revision_notes',
        'reviewed_by',
        'reviewed_at',
        'submitted_at',
        'user_id',
    ];

    protected $casts = [
        'birthdate'    => 'date',
        'reviewed_at'  => 'datetime',
        'submitted_at' => 'datetime',
        'status'       => RegistrationStatusEnum::class,
    ];

    // ── Relationships ──────────────────────────────────────────────────────

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function createdUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    // ── Accessors ──────────────────────────────────────────────────────────

    public function getFullNameAttribute(): string
    {
        $parts = array_filter([
            $this->last_name . ',',
            $this->first_name,
            $this->middle_name,
            $this->suffix,
        ]);
        return implode(' ', $parts);
    }

    public function getFullAddressAttribute(): string
    {
        $parts = array_filter([
            $this->address_house,
            $this->address_street,
            $this->address_barangay,
            $this->address_city,
            $this->address_province,
            $this->address_zip,
        ]);
        return implode(', ', $parts);
    }

    // ── Scopes ────────────────────────────────────────────────────────────

    public function scopePending($query)
    {
        return $query->where('status', RegistrationStatusEnum::PENDING->value);
    }

    public function scopeActionable($query)
    {
        return $query->whereIn('status', [
            RegistrationStatusEnum::PENDING->value,
            RegistrationStatusEnum::NEEDS_REVISION->value,
        ]);
    }

    // ── Helpers ───────────────────────────────────────────────────────────

    public function isPending(): bool
    {
        return $this->status === RegistrationStatusEnum::PENDING;
    }

    public function isApproved(): bool
    {
        return $this->status === RegistrationStatusEnum::APPROVED;
    }

    public function isRejected(): bool
    {
        return $this->status === RegistrationStatusEnum::REJECTED;
    }

    public function needsRevision(): bool
    {
        return $this->status === RegistrationStatusEnum::NEEDS_REVISION;
    }

    /**
     * Detect potential duplicate registrations.
     * Returns existing registrations that share email, contact, or name+birthdate.
     */
    public function detectDuplicates(): \Illuminate\Support\Collection
    {
        return static::where('id', '!=', $this->id)
            ->where(function ($q) {
                $q->where('email', $this->email)
                  ->orWhere('contact_number', $this->contact_number)
                  ->orWhere(function ($q2) {
                      $q2->where('last_name', $this->last_name)
                         ->where('first_name', $this->first_name)
                         ->where('birthdate', $this->birthdate);
                  });
            })
            ->whereIn('status', ['pending', 'approved', 'needs_revision'])
            ->get(['id', 'first_name', 'last_name', 'email', 'contact_number', 'status', 'submitted_at']);
    }

    /**
     * Check if an existing User account matches this registration's email.
     */
    public function findMatchingUser(): ?User
    {
        return User::where('email', $this->email)->first();
    }

    // ── Factory ───────────────────────────────────────────────────────────

    public static function generateTrackingToken(): string
    {
        return Str::upper(Str::random(4)) . '-' . Str::upper(Str::random(4)) . '-' . Str::upper(Str::random(4));
    }
}