<?php

namespace App\Http\Controllers\Accounting;

use App\Http\Controllers\Controller;
use App\Models\CourseUnitPreset;
use App\Models\StudentAssessment;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * CurriculumPresetController
 *
 * Manages the course_unit_presets table through the dedicated
 * Curriculum Preset page (Accounting/CurriculumPreset/Index.vue).
 *
 * This is SEPARATE from CurriculumFeePresetController, which manages the
 * curriculum_fee_presets table (a different, older model). Do not confuse them.
 *
 * Routes:
 *   GET    /accounting/curriculum-presets            → index()
 *   POST   /accounting/curriculum-presets            → store()
 *   GET    /accounting/curriculum-presets/{preset}   → show()  (redirects to PresetSubjects)
 *   PATCH  /accounting/curriculum-presets/{preset}   → update()
 *   DELETE /accounting/curriculum-presets/{preset}   → destroy()
 */
class CurriculumPresetController extends Controller
{
    /**
     * Display the curriculum preset grid, grouped by course → year level → semester.
     *
     * Course filter is applied via query string ?course=...
     * If no course is selected, all active presets are shown.
     */
    public function index(Request $request): Response
    {
        $selectedCourse = $request->input('course');

        // All distinct courses that have at least one preset (active or inactive)
        $courses = CourseUnitPreset::distinct()
            ->orderBy('course')
            ->pluck('course')
            ->values()
            ->toArray();

        $query = CourseUnitPreset::withCount('presetSubjects');

        if ($selectedCourse) {
            $query->where('course', $selectedCourse);
        }

        $presets = $query
            ->orderBy('course')
            ->orderByRaw("FIELD(year_level, '1st Year', '2nd Year', '3rd Year', '4th Year', '5th Year')")
            ->orderByRaw("FIELD(semester, '1st Sem', '2nd Sem', 'Summer')")
            ->get()
            ->map(fn($p) => [
                'id'                => $p->id,
                'course'            => $p->course,
                'year_level'        => $p->year_level,
                'semester'          => $p->semester,
                'lec_units'         => (int) $p->lec_units,
                'lab_units'         => (int) $p->lab_units,
                'lab_subject_count' => (int) $p->lab_subject_count,
                'is_active'         => (bool) $p->is_active,
                'subject_count'     => (int) $p->preset_subjects_count,
                // assessment_count: used for the UX guard warning badge in the UI.
                // Counts assessments tied to this exact course/year/semester combo.
                // This is a read-only count — it never gates the edit flow.
                'assessment_count'  => $this->countLinkedAssessments($p),
            ])
            ->toArray();

        return Inertia::render('Accounting/CurriculumPreset/Index', [
            'courses'        => $courses,
            'selectedCourse' => $selectedCourse,
            'presets'        => $presets,
        ]);
    }

    /**
     * Create a new course unit preset.
     *
     * Only course + year_level + semester are accepted here.
     * All unit aggregates (lec_units, lab_units, lab_subject_count) start at 0
     * and are computed by PresetSubjectController::syncPresetAggregates() when
     * subjects are added.
     *
     * Duplicate guard: one preset per (course, year_level, semester).
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'course'     => ['required', 'string', 'max:150'],
            'year_level' => ['required', 'string', 'in:1st Year,2nd Year,3rd Year,4th Year,5th Year'],
            'semester'   => ['required', 'string', 'in:1st Sem,2nd Sem,Summer'],
        ]);

        $exists = CourseUnitPreset::where('course', $validated['course'])
            ->where('year_level', $validated['year_level'])
            ->where('semester', $validated['semester'])
            ->exists();

        if ($exists) {
            return back()->withErrors([
                'preset' => "A preset for {$validated['course']} — {$validated['year_level']} — {$validated['semester']} already exists.",
            ]);
        }

        CourseUnitPreset::create([
            'course'            => $validated['course'],
            'year_level'        => $validated['year_level'],
            'semester'          => $validated['semester'],
            'lec_units'         => 0,
            'lab_units'         => 0,
            'lab_subject_count' => 0,
            'is_active'         => true,
        ]);

        return redirect()
            ->route('accounting.curriculum-presets.index', ['course' => $validated['course']])
            ->with('success', "Preset for {$validated['course']} {$validated['year_level']} {$validated['semester']} created. Add subjects to populate it.");
    }

    /**
     * Redirect to the PresetSubjects management page for this preset.
     *
     * We reuse the existing PresetSubjects.vue + PresetSubjectController rather
     * than building a duplicate subject management UI. The show route exists
     * for consistent URL structure and breadcrumb navigation.
     */
    public function show(CourseUnitPreset $preset)
    {
        return redirect()->route('accounting.fee-settings.preset-subjects.index', [
            'preset' => $preset->id,
        ]);
    }

    /**
     * Toggle a preset's is_active status.
     *
     * This is the only editable field from the CurriculumPreset UI.
     * Unit aggregates are managed by syncPresetAggregates() in
     * PresetSubjectController — they are never user-editable.
     */
    public function update(Request $request, CourseUnitPreset $preset)
    {
        $validated = $request->validate([
            'is_active' => ['required', 'boolean'],
        ]);

        $preset->update($validated);

        $status = $validated['is_active'] ? 'activated' : 'deactivated';
        return back()->with('success', "{$preset->course} {$preset->year_level} {$preset->semester} {$status}.");
    }

    /**
     * Delete a preset.
     *
     * UX guard: deletion is blocked if the preset has linked subjects.
     * Students who received assessments based on this preset are not affected —
     * assessment_subjects is a snapshot table and holds its own copies of
     * subject data. However, blocking on linked subjects prevents orphaning
     * active curriculum configurations.
     */
    public function destroy(CourseUnitPreset $preset)
    {
        if ($preset->presetSubjects()->exists()) {
            return back()->withErrors([
                'preset' => "Cannot delete this preset — it has {$preset->presetSubjects()->count()} linked subjects. Remove all subjects first via 'Manage Subjects', then delete.",
            ]);
        }

        $label = "{$preset->course} {$preset->year_level} {$preset->semester}";
        $preset->delete();

        return redirect()
            ->route('accounting.curriculum-presets.index')
            ->with('success', "Preset for {$label} deleted.");
    }

    // ─── Private Helpers ──────────────────────────────────────────────────────

    /**
     * Count StudentAssessment records that match this preset's course/year/semester.
     *
     * Used to show the UX guard badge ("X assessments use this preset") in the
     * Index view — informational only, not a gate on editing.
     */
    private function countLinkedAssessments(CourseUnitPreset $preset): int
    {
        return StudentAssessment::where('semester', $preset->semester)
            ->whereHas('user', fn($q) => $q
                ->where('course', $preset->course)
                ->where('year_level', $preset->year_level)
            )
            ->count();
    }
}