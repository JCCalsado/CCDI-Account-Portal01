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
 * Routes:
 *   GET    /accounting/curriculum-presets                      → index()
 *   POST   /accounting/curriculum-presets                      → store()  → redirects to subjects page
 *   GET    /accounting/curriculum-presets/{preset}/subjects    → PresetSubjectController::curriculumIndex()
 *   PATCH  /accounting/curriculum-presets/{preset}             → update()
 *   DELETE /accounting/curriculum-presets/{preset}             → destroy()
 *
 * NOTE: show() is removed. The old redirect to fee-settings.preset-subjects.index
 * is replaced by the dedicated subjects sub-routes above. Index.vue now links
 * directly to accounting.curriculum-presets.subjects.index.
 */
class CurriculumPresetController extends Controller
{
    /**
     * Display the curriculum preset grid, grouped by course → year level → semester.
     */
    public function index(Request $request): Response
    {
        $selectedCourse = $request->input('course');

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
     * Create a new course unit preset, then redirect immediately to its
     * subjects page (Decision B1) so the user can populate it right away.
     *
     * A ?new=1 flag is passed to the subjects page so a "just created" banner
     * can be shown.
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

        $preset = CourseUnitPreset::create([
            'course'            => $validated['course'],
            'year_level'        => $validated['year_level'],
            'semester'          => $validated['semester'],
            'lec_units'         => 0,
            'lab_units'         => 0,
            'lab_subject_count' => 0,
            'is_active'         => true,
        ]);

        // Decision B1: redirect directly to the subjects management page.
        // ?new=1 triggers the "Add your subjects" onboarding banner in Subjects.vue.
        return redirect()
            ->route('accounting.curriculum-presets.subjects.index', ['preset' => $preset->id, 'new' => 1])
            ->with('success', "Preset created. Now add subjects to populate it.");
    }

    /**
     * Toggle a preset's is_active status.
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
     * Blocked if the preset has linked subjects — prevents orphaning
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