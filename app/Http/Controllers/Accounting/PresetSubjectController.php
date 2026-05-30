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
 * Manages the course_unit_preset_subjects pivot table — which subjects
 * belong to a given CourseUnitPreset and their per-subject fee snapshot.
 *
 * NSTP detection: uses subjects.is_nstp (DB flag). No string-sniffing.
 * PATHFIT: treated identically to regular billable subjects. No special branch.
 * is_pathfit column: retained in assessment_subjects for historical data only.
 *   We do NOT write it here (PresetSubjectController has no assessment_subjects rows).
 * has_nstp column: dropped from course_unit_presets. syncPresetAggregates()
 *   no longer writes it.
 */
class PresetSubjectController extends Controller
{
    // ─── Index ────────────────────────────────────────────────────────────────

    public function index(CourseUnitPreset $preset): Response
    {
        $preset->load('presetSubjects.subject');

        $semesterDb = AssessmentService::normalizeSemester($preset->semester);

        $allSubjects = Subject::where('course', $preset->course)
            ->where('year_level', $preset->year_level)
            ->where('semester', $semesterDb)
            ->where('is_active', true)
            ->orderBy('code')
            ->get();

        $linkedSubjectIds = $preset->presetSubjects->pluck('subject_id')->toArray();

        $availableSubjects = $allSubjects
            ->whereNotIn('id', $linkedSubjectIds)
            ->values()
            ->map(fn (Subject $s) => [
                'id'        => $s->id,
                'code'      => $s->code,
                'name'      => $s->name,
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

        return Inertia::render('Accounting/PresetSubjects', [
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
                'tuition_per_unit'    => $rates['tuition_per_unit'],
                'lab_fee_per_subject' => $rates['lab_fee_per_subject'],
            ],
            'backUrl' => route('accounting.fee-settings.index'),
        ]);
    }

    // ─── Store ────────────────────────────────────────────────────────────────

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

        $isNstp = (bool) $subject->is_nstp;

        $rates = AssessmentService::loadRates();
        $fees  = AssessmentService::computeSubjectFees(
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

    // ─── Destroy ──────────────────────────────────────────────────────────────

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

    // ─── Sync ─────────────────────────────────────────────────────────────────

    public function sync(CourseUnitPreset $preset)
    {
        $rates = AssessmentService::loadRates();

        DB::transaction(function () use ($preset, $rates) {
            $rows = CourseUnitPresetSubject::where('course_unit_preset_id', $preset->id)
                ->with('subject')
                ->get();

            foreach ($rows as $ps) {
                $isNstp = (bool) $ps->is_nstp;

                $fees = AssessmentService::computeSubjectFees(
                    $isNstp,
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

    // ─── Private helpers ──────────────────────────────────────────────────────

    /**
     * Recalculate and persist the aggregate unit counts on the preset
     * from its linked subjects.
     *
     * NSTP subjects: their lec_units are excluded from the billable aggregate
     * because AssessmentService::compute() handles NSTP billing separately
     * via the nstpLecUnits accumulator in getCurriculumUnits().
     *
     * PATHFIT: treated as regular billable subjects. No special skip.
     *
     * has_nstp column was dropped from course_unit_presets — not written here.
     */
    private function syncPresetAggregates(CourseUnitPreset $preset): void
    {
        $subjects = CourseUnitPresetSubject::where('course_unit_preset_id', $preset->id)->get();

        $lecUnits        = 0.0;
        $labSubjectCount = 0;

        foreach ($subjects as $ps) {
            if ($ps->is_nstp) {
                // NSTP lec_units excluded — handled separately by AssessmentService
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