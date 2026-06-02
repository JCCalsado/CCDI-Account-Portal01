<?php

namespace App\Http\Controllers\Accounting;

use App\Http\Controllers\Controller;
use App\Models\CourseUnitPreset;
use App\Models\CourseUnitPresetSubject;
use App\Models\Subject;
use App\Services\AssessmentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * PresetSubjectController
 *
 * Manages the course_unit_preset_subjects pivot table.
 *
 * TWO ENTRY POINTS (same data, different navigation context):
 *
 *   1. curriculumIndex() — Renders Accounting/CurriculumPreset/Subjects.vue
 *      Route: GET /accounting/curriculum-presets/{preset}/subjects
 *      Breadcrumb: Dashboard → Curriculum Presets → [Preset Label]
 *      Back button: ← Back to Curriculum Presets
 *      Used by: CurriculumPreset/Index.vue → "Manage Subjects" button
 *
 *   2. index() — Renders Accounting/PresetSubjects.vue (legacy)
 *      Route: GET /accounting/fee-settings/presets/{preset}/subjects
 *      Breadcrumb: Dashboard → Fee Settings → [Preset Label]
 *      Back button: ← Back to Fee Settings
 *      Used by: any legacy deep links that still point to fee-settings context.
 *
 * store(), destroy(), sync() are shared — routes in both namespaces point here.
 *
 * ── Summer Semester Logic ──────────────────────────────────────────────────────
 *
 *   Subjects are classified as '1st Sem' or '2nd Sem' only. Summer is a
 *   PRESET classification (course_unit_presets.semester = 'Summer'), not a
 *   subject classification. No subject ever has semester = 'Summer'.
 *
 *   CCDI Summer classes offer subjects from both the 1st Sem and 2nd Sem of
 *   the same year level in a compressed 1–1.5 month schedule. A Summer preset
 *   therefore draws its availableSubjects from semester IN ('1st Sem', '2nd Sem')
 *   for the same course and year_level.
 *
 *   This is handled exclusively in buildPageData() — nowhere else in the system
 *   needs to know this rule.
 */
class PresetSubjectController extends Controller
{
    // ─── Curriculum Context (primary) ─────────────────────────────────────────

    /**
     * Render the subject management page in the Curriculum Presets context.
     * This is the authoritative entry point going forward.
     */
    public function curriculumIndex(Request $request, CourseUnitPreset $preset): Response
    {
        $data = $this->buildPageData($preset);

        return Inertia::render('Accounting/CurriculumPreset/Subjects', array_merge($data, [
            'backUrl'      => route('accounting.curriculum-presets.index', ['course' => $preset->course]),
            'isNew'        => $request->boolean('new'),
            'storeRoute'   => route('accounting.curriculum-presets.subjects.store', $preset->id),
            'destroyRoute' => 'accounting.curriculum-presets.subjects.destroy',
            'syncRoute'    => route('accounting.curriculum-presets.subjects.sync', $preset->id),
        ]));
    }

    // ─── Legacy Fee-Settings Context ──────────────────────────────────────────

    /**
     * Render the subject management page in the Fee Settings context (legacy).
     * Kept for backward compatibility with any existing direct links.
     */
    public function index(CourseUnitPreset $preset): Response
    {
        $data = $this->buildPageData($preset);

        return Inertia::render('Accounting/PresetSubjects', array_merge($data, [
            'backUrl'      => route('accounting.fee-settings.index'),
            'isNew'        => false,
            'storeRoute'   => route('accounting.fee-settings.preset-subjects.store', $preset->id),
            'destroyRoute' => 'accounting.fee-settings.preset-subjects.destroy',
            'syncRoute'    => route('accounting.fee-settings.preset-subjects.sync', $preset->id),
        ]));
    }

    // ─── Shared Write Actions ─────────────────────────────────────────────────

    public function store(Request $request, CourseUnitPreset $preset)
    {
        $validated = $request->validate([
            'subject_id' => ['required', 'integer', 'exists:subjects,id'],
        ]);

        $alreadyLinked = CourseUnitPresetSubject::where('course_unit_preset_id', $preset->id)
            ->where('subject_id', $validated['subject_id'])
            ->exists();

        if ($alreadyLinked) {
            return back()->withErrors(['subject_id' => 'This subject is already linked to this preset.']);
        }

        $subject = Subject::findOrFail((int) $validated['subject_id']);
        $isNstp  = (bool) $subject->is_nstp;
        $rates   = AssessmentService::loadRates();
        $fees    = AssessmentService::computeSubjectFees(
            $isNstp,
            (float) $subject->lec_units,
            (int)   $subject->lab_units,
            $rates
        );

        $maxSort = CourseUnitPresetSubject::where('course_unit_preset_id', $preset->id)
            ->max('sort_order') ?? 0;

        CourseUnitPresetSubject::create([
            'course_unit_preset_id' => $preset->id,
            'subject_id'            => $subject->id,
            'lec_units'             => $subject->lec_units,
            'lab_units'             => (int) $subject->lab_units,
            'tuition_fee'           => $fees['tuition_fee'],
            'lab_fee'               => $fees['lab_fee'],
            'total_fee'             => $fees['total_fee'],
            'is_nstp'               => $isNstp,
            'sort_order'            => $maxSort + 1,
        ]);

        $this->syncPresetAggregates($preset);

        return back()->with('success', "Subject \"{$subject->code} — {$subject->name}\" added to preset.");
    }

    public function destroy(CourseUnitPreset $preset, CourseUnitPresetSubject $presetSubject)
    {
        if ($presetSubject->course_unit_preset_id !== $preset->id) {
            abort(404, 'Subject not found on this preset.');
        }

        $code = $presetSubject->subject?->code ?? 'Subject';
        $presetSubject->delete();

        $this->syncPresetAggregates($preset);

        return back()->with('success', "Subject \"{$code}\" removed from preset.");
    }

    public function sync(CourseUnitPreset $preset)
    {
        $rates = AssessmentService::loadRates();

        DB::transaction(function () use ($preset, $rates) {
            $rows = CourseUnitPresetSubject::where('course_unit_preset_id', $preset->id)
                ->with('subject')
                ->get();

            foreach ($rows as $ps) {
                $fees = AssessmentService::computeSubjectFees(
                    (bool) $ps->is_nstp,
                    (float) $ps->lec_units,
                    (int)   $ps->lab_units,
                    $rates
                );

                $ps->update([
                    'tuition_fee' => $fees['tuition_fee'],
                    'lab_fee'     => $fees['lab_fee'],
                    'total_fee'   => $fees['total_fee'],
                ]);
            }
        });

        return back()->with('success', 'Per-subject fees synced to current rates.');
    }

    // ─── Private Helpers ──────────────────────────────────────────────────────

    /**
     * Build the shared page data array used by both index() and curriculumIndex().
     *
     * ── Summer Preset Handling ────────────────────────────────────────────────
     *
     * When $preset->semester === 'Summer', availableSubjects are pulled from
     * BOTH '1st Sem' and '2nd Sem' of the same year_level. This reflects CCDI
     * Summer policy: Summer classes are drawn from the full year's subjects,
     * not a separate Summer subject registry. No subject row ever has
     * semester = 'Summer'.
     *
     * For all other semesters ('1st Sem', '2nd Sem'), the query matches exactly.
     *
     * Note: normalizeSemester() has been intentionally removed. preset->semester
     * is already in long format ('1st Sem', '2nd Sem', 'Summer') — enforced by
     * CurriculumPresetController validation. Calling normalizeSemester() was a
     * no-op via the default branch and added false ambiguity.
     */
    private function buildPageData(CourseUnitPreset $preset): array
    {
        $preset->load('presetSubjects.subject');

        // ── Subject query — Summer-aware ───────────────────────────────────────
        $subjectQuery = Subject::where('course', $preset->course)
            ->where('year_level', $preset->year_level)
            ->where('is_active', true)
            ->orderBy('semester')   // '1st Sem' before '2nd Sem' alphabetically
            ->orderBy('code');

        if ($preset->semester === 'Summer') {
            // Summer presets pool subjects from both regular semesters of
            // the same year level. Subjects themselves are never tagged 'Summer'.
            $subjectQuery->whereIn('semester', ['1st Sem', '2nd Sem']);
        } else {
            // 1st Sem and 2nd Sem presets match their semester exactly.
            $subjectQuery->where('semester', $preset->semester);
        }

        $allSubjects = $subjectQuery->get();

        $linkedSubjectIds = $preset->presetSubjects->pluck('subject_id')->toArray();

        $availableSubjects = $allSubjects
            ->whereNotIn('id', $linkedSubjectIds)
            ->values()
            ->map(fn(Subject $s) => [
                'id'        => $s->id,
                'code'      => $s->code,
                'name'      => $s->name,
                'semester'  => $s->semester,    // expose semester so Vue can group them (1st Sem / 2nd Sem)
                'lec_units' => $s->lec_units,
                'lab_units' => $s->lab_units,
                'is_nstp'   => (bool) $s->is_nstp,
            ]);

        $rates = AssessmentService::loadRates();

        $linkedSubjects = $preset->presetSubjects->map(function (CourseUnitPresetSubject $ps) use ($rates) {
            $isNstp = (bool) $ps->is_nstp;

            $currentFees = AssessmentService::computeSubjectFees(
                $isNstp,
                (float) $ps->lec_units,
                (int)   $ps->lab_units,
                $rates
            );

            return [
                'id'                => $ps->id,
                'subject_id'        => $ps->subject_id,
                'code'              => $ps->subject?->code ?? '—',
                'name'              => $ps->subject?->name ?? '—',
                'semester'          => $ps->subject?->semester ?? null,
                'lec_units'         => $ps->lec_units,
                'lab_units'         => $ps->lab_units,
                'is_nstp'           => $isNstp,
                'sort_order'        => $ps->sort_order,
                'tuition_fee'       => (float) $ps->tuition_fee,
                'lab_fee'           => (float) $ps->lab_fee,
                'total_fee'         => (float) $ps->total_fee,
                'current_tuition'   => $currentFees['tuition_fee'],
                'current_lab_fee'   => $currentFees['lab_fee'],
                'current_total_fee' => $currentFees['total_fee'],
                'fees_are_stale'    => abs($currentFees['total_fee'] - (float) $ps->total_fee) > 0.005,
            ];
        });

        return [
            'preset' => [
                'id'                => $preset->id,
                'course'            => $preset->course,
                'year_level'        => $preset->year_level,
                'semester'          => $preset->semester,
                'lec_units'         => $preset->lec_units,
                'lab_units'         => $preset->lab_units,
                'lab_subject_count' => $preset->lab_subject_count,
                'is_active'         => $preset->is_active,
            ],
            'linkedSubjects'    => $linkedSubjects,
            'availableSubjects' => $availableSubjects,
            'rates'             => [
                'tuition_per_unit'     => $rates['tuition_per_unit'],
                'lab_fee_per_subject'  => $rates['lab_fee_per_subject'],
                'entrepreneurship_fee' => $rates['entrepreneurship_fee'],
            ],
        ];
    }

    /**
     * Recalculate and persist the aggregate unit counts on the preset.
     * NSTP lec_units excluded — handled separately by AssessmentService.
     */
    private function syncPresetAggregates(CourseUnitPreset $preset): void
    {
        $subjects = CourseUnitPresetSubject::where('course_unit_preset_id', $preset->id)->get();

        $lecUnits        = 0.0;
        $labSubjectCount = 0;

        foreach ($subjects as $ps) {
            if ($ps->is_nstp) {
                continue;
            }
            $lecUnits += (float) $ps->lec_units;
            if ((int) $ps->lab_units > 0) {
                $labSubjectCount++;
            }
        }

        $preset->update([
            'lec_units'         => $lecUnits,
            'lab_units'         => $labSubjectCount,
            'lab_subject_count' => $labSubjectCount,
        ]);
    }
}