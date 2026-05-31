// Base User type — mirrors the resolveAuthUser() output in HandleInertiaRequests.
// Keep this in sync with that method when adding new shared fields.
export interface User {
    id: number;
    name: string;           // "LAST, First MI." — computed accessor
    first_name: string;
    last_name: string;
    middle_name?: string | null;     // Full middle name — primary field
    middle_initial?: string | null;  // Computed from middle_name; stored as fallback for legacy rows
    suffix?: string | null;
    gender?: string | null;
    civil_status?: string | null;
    email: string;
    role: string;           // 'admin' | 'accounting' | 'student'

    avatar?: string | null;          // Full URL built from profile_picture — for display
    profile_picture?: string | null; // Raw storage path — for settings page
    email_verified_at?: string | null;

    is_active?: boolean;
    faculty?: string | null;
    department?: string | null;
}

// StudentUser extends User with student-specific fields
export interface StudentUser extends User {
    account_id: string;
    course: string;
    year_level: string;
    is_irregular?: boolean;
    status?: 'active' | 'graduated' | 'dropped';

    // Contact
    phone?: string | null;
    birthday?: string | null;       // ISO date string "YYYY-MM-DD"

    // Address — decomposed; old single `address` column was dropped in 2026_05_11 migration
    address_house_lot_unit?: string | null;
    address_street_name?: string | null;
    address_barangay?: string | null;
    address_municipality_city?: string | null;
    address_province?: string | null;
    address_zip?: string | null;

    // Guardian / Emergency
    guardian_name?: string | null;
    guardian_contact?: string | null;
    emergency_contact?: string | null;
}