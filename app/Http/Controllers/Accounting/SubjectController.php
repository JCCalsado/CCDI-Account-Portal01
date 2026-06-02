<?php

namespace App\Http\Controllers\Accounting;

use App\Enums\UserRoleEnum;
use App\Http\Controllers\Controller;
use App\Models\CourseUnitPreset;
use App\Models\Subject;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * SubjectController (Accounting namespace)
 *
 * Full CRUD for curriculum subjects, accessible to accounting and admin roles.
 *
 * ── Canonical value sets ──────────────────────────────────────────────────────
 *
 *   YEAR_LEVELS and SEMESTERS are finite institution-defined constants.
 *   They must NEVER be derived from the subjects table (circular dependency)
 *   or from any other DB query. Any change to valid year levels or semesters
 *   is a curriculum policy decision that requires updating these constants AND
 *   running a data migration for existing rows.
 *
 * ── Course source of truth ────────────────────────────────────────────────────
 *
 *   Courses are sourced from course_unit_presets — the authoritative registry
 *   of programs offered by the institution. A subject must belong to a course
 *   that already has a preset. This enforces referential integrity at the
 *   application layer (no FK exists on the DB level).
 *
 * ── Summer semesters ─────────────────────────────────────────────────────────
 *
 *   Subjects are classified as '1st Sem' or '2nd Sem' only. Summer is a
 *   PRESET type (course_unit_presets.semester = 'Summer'), not a subject
 *   classification. Summer presets draw from existing 1st/2nd Sem subjects
 *   of the same year level. No subject should ever have semester = 'Summer'.
 *
 * ── is_nstp flag ─────────────────────────────────────────────────────────────
 *
 *   Visible and editable only by admin role. Accounting staff can edit
 *   lec_units / lab_units but cannot change the NSTP flag — that has billing
 *   implications that only admin should control.
 *
 * Routes registered under /accounting/subjects (see routes/web.php).
 */
class SubjectController extends Controller
{
    // ─── Canonical Constants ──────────────────────────────────────────────────
    //
    // These are the ONLY valid values for year_level and semester on a subject row.
    // They are validated on every store() and update() call.
    //
    // Note: 'Summer' is intentionally absent from SEMESTERS. Summer is a preset
    // type, not a subject classification. See class docblock above.

    private const YEAR_LEVELS = [
        '1st Year',
        '2nd Year',
        '3rd Year',
        '4th Year',
        '5th Year',
    ];

    private const SEMESTERS = [
        '1st Sem',
        '2nd Sem',
    ];

    // ─── Index ────────────────────────────────────────────────────────────────

    public function index(Request $request): Response
    {
        $query = Subject::query()->where('is_active', true);

        if ($request->filled('course')) {
            $query->where('course', $request->course);
        }

        if ($request->filled('year_level')) {
            $query->where('year_level', $request->year_level);
        }

        if ($request->filled('semester')) {
            $query->where('semester', $request->semester);
        }

        if ($request->filled('search')) {
            $s = $request->search;
            $query->where(function ($q) use ($s) {
                $q->where('name', 'like', "%{$s}%")
                  ->orWhere('code', 'like', "%{$s}%");
            });
        }

        $subjects = $query
            ->orderBy('course')
            ->orderBy('year_level')
            ->orderBy('semester')
            ->orderBy('code')
            ->paginate(50)
            ->through(fn (Subject $s) => [
                'id'         => $s->id,
                'code'       => $s->code,
                'name'       => $s->name,
                'lec_units'  => $s->lec_units,
                'lab_units'  => $s->lab_units,
                'year_level' => $s->year_level,
                'semester'   => $s->semester,
                'course'     => $s->course,
                'is_active'  => $s->is_active,
                'is_nstp'    => (bool) $s->is_nstp,
            ]);

        $subjects->appends($request->only(['course', 'year_level', 'semester', 'search']));

        // Courses sourced from course_unit_presets — the authoritative program registry.
        // This is NOT derived from the subjects table, avoiding circular dependency.
        $courses = CourseUnitPreset::distinct()
            ->orderBy('course')
            ->pluck('course')
            ->values();

        return Inertia::render('Subjects/Index', [
            'subjects'    => $subjects,
            'filters'     => $request->only(['course', 'year_level', 'semester', 'search']),
            'courses'     => $courses,
            // yearLevels and semesters are canonical constants — Vue defines them
            // locally via YEAR_LEVELS / SEMESTERS. They are NOT passed as Inertia
            // props to avoid the illusion that they are dynamic/configurable data.
            'canEditNstp' => $this->canEditNstp(),
            'canCreate'   => $this->canCreate(),
        ]);
    }

    // ─── Create ───────────────────────────────────────────────────────────────

    public function create(Request $request): Response
    {
        $courses = CourseUnitPreset::distinct()
            ->orderBy('course')
            ->pluck('course')
            ->values();

        return Inertia::render('Subjects/Create', [
            'courses'       => $courses,
            'canEditNstp'   => $this->canEditNstp(),
            // defaultValues: pre-fill the form when arriving from the Preset Subjects
            // empty-state link (e.g., /subjects/create?course=BS+IT&year_level=4th+Year&semester=1st+Sem).
            // Vue reads these to initialise the form so the user only needs to
            // fill in code, name, and units.
            'defaultValues' => [
                'course'     => $request->query('course', ''),
                'year_level' => $request->query('year_level', ''),
                'semester'   => $request->query('semester', ''),
            ],
        ]);
    }

    // ─── Store ────────────────────────────────────────────────────────────────

    public function store(Request $request)
    {
        // Resolve valid courses at request time — not at class-load time — so
        // newly created presets are immediately available as valid courses.
        $validCourses = CourseUnitPreset::distinct()->pluck('course')->toArray();

        $rules = [
            'code'       => ['required', 'string', 'max:50', 'unique:subjects,code'],
            'name'       => ['required', 'string', 'max:255'],
            'lec_units'  => ['required', 'numeric', 'min:0', 'max:10'],
            'lab_units'  => ['required', 'integer', 'min:0', 'max:5'],
            'year_level' => ['required', 'string', Rule::in(self::YEAR_LEVELS)],
            'semester'   => ['required', 'string', Rule::in(self::SEMESTERS)],
            'course'     => ['required', 'string', Rule::in($validCourses)],
        ];

        if ($this->canEditNstp()) {
            $rules['is_nstp'] = ['sometimes', 'boolean'];
        }

        $validated = $request->validate($rules);

        $validated['lec_units'] = (float) $validated['lec_units'];
        $validated['lab_units'] = (int)   $validated['lab_units'];
        $validated['is_active'] = true;

        if (! $this->canEditNstp()) {
            // Accounting staff: auto-detect NSTP from code as a safe default
            $validated['is_nstp'] = str_contains(strtoupper($validated['code']), 'NSTP');
        } else {
            $validated['is_nstp'] = (bool) ($validated['is_nstp'] ?? false);
        }

        $subject = Subject::create($validated);

        return redirect()
            ->route('accounting.subjects.index')
            ->with('success', "Subject \"{$subject->code} — {$subject->name}\" created.");
    }

    // ─── Edit ─────────────────────────────────────────────────────────────────

    public function edit(Subject $subject): Response
    {
        $courses = CourseUnitPreset::distinct()
            ->orderBy('course')
            ->pluck('course')
            ->values();

        return Inertia::render('Subjects/Edit', [
            'subject' => [
                'id'         => $subject->id,
                'code'       => $subject->code,
                'name'       => $subject->name,
                'lec_units'  => $subject->lec_units,
                'lab_units'  => $subject->lab_units,
                'year_level' => $subject->year_level,
                'semester'   => $subject->semester,
                'course'     => $subject->course,
                'is_nstp'    => (bool) $subject->is_nstp,
                'is_active'  => $subject->is_active,
            ],
            'courses'     => $courses,
            'canEditNstp' => $this->canEditNstp(),
        ]);
    }

    // ─── Update ───────────────────────────────────────────────────────────────

    public function update(Request $request, Subject $subject)
    {
        $validCourses = CourseUnitPreset::distinct()->pluck('course')->toArray();

        $rules = [
            'code'       => ['required', 'string', 'max:50', 'unique:subjects,code,' . $subject->id],
            'name'       => ['required', 'string', 'max:255'],
            'lec_units'  => ['required', 'numeric', 'min:0', 'max:10'],
            'lab_units'  => ['required', 'integer', 'min:0', 'max:5'],
            'year_level' => ['required', 'string', Rule::in(self::YEAR_LEVELS)],
            'semester'   => ['required', 'string', Rule::in(self::SEMESTERS)],
            'course'     => ['required', 'string', Rule::in($validCourses)],
            'is_active'  => ['sometimes', 'boolean'],
        ];

        if ($this->canEditNstp()) {
            $rules['is_nstp'] = ['sometimes', 'boolean'];
        }

        $validated = $request->validate($rules);

        $validated['lec_units'] = (float) $validated['lec_units'];
        $validated['lab_units'] = (int)   $validated['lab_units'];

        if (! $this->canEditNstp()) {
            unset($validated['is_nstp']);
        } else {
            $validated['is_nstp'] = (bool) ($validated['is_nstp'] ?? $subject->is_nstp);
        }

        $subject->update($validated);

        return redirect()
            ->route('accounting.subjects.index')
            ->with('success', "Subject \"{$subject->code} — {$subject->name}\" updated.");
    }

    // ─── Inline Update (AJAX) ─────────────────────────────────────────────────

    public function inlineUpdate(Request $request, Subject $subject): \Illuminate\Http\JsonResponse
    {
        $validated = $request->validate([
            'lec_units' => ['required', 'numeric', 'min:0', 'max:10'],
            'lab_units' => ['required', 'integer', 'min:0', 'max:5'],
        ]);

        $validated['lec_units'] = (float) $validated['lec_units'];
        $validated['lab_units'] = (int)   $validated['lab_units'];

        $subject->update($validated);

        return response()->json([
            'success'   => true,
            'lec_units' => $subject->fresh()->lec_units,
            'lab_units' => $subject->fresh()->lab_units,
        ]);
    }

    // ─── Destroy ──────────────────────────────────────────────────────────────

    public function destroy(Subject $subject)
    {
        // Deletion requires admin. This uses isAdmin() — not canEditNstp() — because
        // these are two distinct authorization concerns that happen to share the same
        // role requirement today. Keeping them separate prevents a silent privilege
        // change if canEditNstp() is ever broadened to non-admin roles.
        if (! $this->isAdmin()) {
            abort(403, 'Only administrators can delete subjects.');
        }

        $label = "{$subject->code} — {$subject->name}";

        // Soft-deactivate instead of hard delete to preserve assessment history
        $subject->update(['is_active' => false]);

        return redirect()
            ->route('accounting.subjects.index')
            ->with('success', "Subject \"{$label}\" deactivated.");
    }

    // ─── Private Helpers ──────────────────────────────────────────────────────

    /**
     * Only admin role may set or change the is_nstp flag.
     * Accounting staff can edit units but the NSTP classification
     * has billing consequences and must be admin-controlled.
     */
    private function canEditNstp(): bool
    {
        return auth()->user()?->role === UserRoleEnum::ADMIN;
    }

    /**
     * Whether the current user is an administrator.
     *
     * Intentionally separate from canEditNstp() even though both check for
     * ADMIN today. If NSTP editing is ever delegated to a non-admin role,
     * the delete guard (isAdmin) must not silently inherit that change.
     */
    private function isAdmin(): bool
    {
        return auth()->user()?->role === UserRoleEnum::ADMIN;
    }

    /**
     * Both accounting and admin roles may create new subjects.
     * canEditNstp() is a stricter gate — admin only — for the NSTP flag.
     */
    private function canCreate(): bool
    {
        $role = auth()->user()?->role;
        return in_array($role, [UserRoleEnum::ADMIN, UserRoleEnum::ACCOUNTING], true);
    }
}