<?php

namespace App\Http\Controllers\Accounting;

use App\Enums\UserRoleEnum;
use App\Http\Controllers\Controller;
use App\Models\Subject;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * SubjectController (Accounting namespace)
 *
 * Full CRUD for curriculum subjects, accessible to accounting and admin roles.
 *
 * is_nstp field: visible and editable only by admin role.
 *   Accounting staff can edit lec_units / lab_units but cannot change the
 *   NSTP flag — that has billing implications that only admin should control.
 *
 * Routes registered under /accounting/subjects (see routes/web.php).
 */
class SubjectController extends Controller
{
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

        $courses    = Subject::distinct()->pluck('course')->sort()->values();
        $yearLevels = Subject::distinct()->pluck('year_level')->sort()->values();
        $semesters  = Subject::distinct()->pluck('semester')->sort()->values();

        return Inertia::render('Subjects/Index', [
            'subjects'   => $subjects,
            'filters'    => $request->only(['course', 'year_level', 'semester', 'search']),
            'courses'    => $courses,
            'yearLevels' => $yearLevels,
            'semesters'  => $semesters,
            'canEditNstp' => $this->canEditNstp(),
        ]);
    }

    // ─── Create ───────────────────────────────────────────────────────────────

    public function create(): Response
    {
        $courses    = Subject::distinct()->pluck('course')->sort()->values();
        $yearLevels = Subject::distinct()->pluck('year_level')->sort()->values();
        $semesters  = Subject::distinct()->pluck('semester')->sort()->values();

        return Inertia::render('Subjects/Create', [
            'courses'     => $courses,
            'yearLevels'  => $yearLevels,
            'semesters'   => $semesters,
            'canEditNstp' => $this->canEditNstp(),
        ]);
    }

    // ─── Store ────────────────────────────────────────────────────────────────

    public function store(Request $request)
    {
        $rules = [
            'code'       => ['required', 'string', 'max:50', 'unique:subjects,code'],
            'name'       => ['required', 'string', 'max:255'],
            'lec_units'  => ['required', 'numeric', 'min:0', 'max:10'],
            'lab_units'  => ['required', 'integer', 'min:0', 'max:5'],
            'year_level' => ['required', 'string', 'max:50'],
            'semester'   => ['required', 'string', 'max:50'],
            'course'     => ['required', 'string', 'max:100'],
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
            'courses'     => Subject::distinct()->pluck('course')->sort()->values(),
            'yearLevels'  => Subject::distinct()->pluck('year_level')->sort()->values(),
            'semesters'   => Subject::distinct()->pluck('semester')->sort()->values(),
            'canEditNstp' => $this->canEditNstp(),
        ]);
    }

    // ─── Update ───────────────────────────────────────────────────────────────

    public function update(Request $request, Subject $subject)
    {
        $rules = [
            'code'       => ['required', 'string', 'max:50', 'unique:subjects,code,' . $subject->id],
            'name'       => ['required', 'string', 'max:255'],
            'lec_units'  => ['required', 'numeric', 'min:0', 'max:10'],
            'lab_units'  => ['required', 'integer', 'min:0', 'max:5'],
            'year_level' => ['required', 'string', 'max:50'],
            'semester'   => ['required', 'string', 'max:50'],
            'course'     => ['required', 'string', 'max:100'],
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
        // Only admin can delete subjects — accounting can only edit units
        if (! $this->canEditNstp()) {
            abort(403, 'Only administrators can delete subjects.');
        }

        $label = "{$subject->code} — {$subject->name}";

        // Soft-deactivate instead of hard delete to preserve assessment history
        $subject->update(['is_active' => false]);

        return redirect()
            ->route('accounting.subjects.index')
            ->with('success', "Subject \"{$label}\" deactivated.");
    }

    // ─── Private helpers ──────────────────────────────────────────────────────

    /**
     * Only admin role may set or change the is_nstp flag.
     * Accounting staff can edit units but the NSTP classification
     * has billing consequences and must be admin-controlled.
     */
    private function canEditNstp(): bool
    {
        return auth()->user()?->role === UserRoleEnum::ADMIN;
    }
}