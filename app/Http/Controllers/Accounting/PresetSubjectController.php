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
 * This controller is accounting-only. Routes are registered under:
 *   /accounting/fee-settings/presets/{preset}/subjects
 *
 * ROUTES (add to routes/web.php under the accounting middleware group):
 *
 *   GET    /accounting/fee-settings/presets/{preset}/subjects       → index
 *   POST   /accounting/fee-settings/presets/{preset}/subjects       → store
 *   DELETE /accounting/fee-settings/presets/{preset}/subjects/{ps}  → destroy
 *   POST   /accounting/fee-settings/presets/{preset}/subjects/sync  → sync
 */
class PresetSubjectController extends Controller
{
    // ─── Index ────────────────────────────────────────────────────────────────

    /**
     * Show the preset-subject management page for a specific preset.
     *
     * Passes:
     *   - preset: the CourseUnitPreset with its linked subjects
     *   - availableSubjects: subjects that match this preset's course/year/semester
     *     but are NOT already linked — used to populate the "Add Subject" dropdown
     *   - rates: current fee rates for the "Sync Fees" preview
     */
    public function index(CourseUnitPreset $preset): Response
    {
        $preset->load('presetSubjects.subject');

        $semesterDb = AssessmentService::normalizeSemester($preset->semester);

        // All active subjects for this preset's course + year level + semester
        $allSubjects = Subject::where('course', $preset->course)
            ->where('year_level', $preset->year_level)
            ->where('semester', $semesterDb)
            ->where('is_active', true)
            ->orderBy('code')
            ->get();

        // IDs already linked to this preset
        $linkedSubjectIds = $preset->presetSubjects->pluck('subject_id')->toArray();

        $availableSubjects = $allSubjects
            ->whereNotIn('id', $linkedSubjectIds)
            ->values()
            ->map(fn ($s) => [
                'id'        => $s->id,
                'code'      => $s->code,
                'name'      => $s->name,
                'lec_units' => $s->lec_units,
                'lab_units' => $s->lab_units,
                'is_nstp'   => AssessmentService::isNstpSubject($s->code, $s->name),
            ]);

        $rates = AssessmentService::loadRates();

        $linkedSubjects = $preset->presetSubjects->map(function ($ps) use ($rates) {
            $isNstp    = AssessmentService::isNstpSubject($ps->code ?? '', $ps->subject?->name ?? '');
            $isPathfit = AssessmentService::isPathfitSubject($ps->code ?? '', $ps->subject?->name ?? '');

            // Compute what the fee WOULD be at current rates (for sync preview)
            $currentFees = AssessmentService::computeSubjectFees(
                $isNstp,
                $isPathfit,
                (int) $ps->lec_units,
                (int) $ps->lab_units,
                $rates
            );

            return [
                'id'                => $ps->id,
                'subject_id'        => $ps->subject_id,
                'code'              => $ps->code ?? $ps->subject?->code ?? '—',
                'name'              => $ps->subject?->name ?? '—',
                'lec_units'         => $ps->lec_units,
                'lab_units'         => $ps->lab_units,
                'is_nstp'           => (bool) $ps->is_nstp,
                'is_pathfit'        => (bool) $ps->is_pathfit,
                'sort_order'        => $ps->sort_order,
                // Stored fees (locked at assignment time)
                'tuition_fee'       => (float) $ps->tuition_fee,
                'lab_fee'           => (float) $ps->lab_fee,
                'total_fee'         => (float) $ps->total_fee,
                // Current-rate fees (for sync preview — may differ if rates changed)
                'current_tuition'   => $currentFees['tuition_fee'],
                'current_lab_fee'   => $currentFees['lab_fee'],
                'current_total_fee' => $currentFees['total_fee'],
                'fees_are_stale'    => abs($currentFees['total_fee'] - (float) $ps->total_fee) > 0.005,
            ];
        });

        return Inertia::render('Accounting/PresetSubjects', [
            'preset'            => [
                'id'                => $preset->id,
                'course'            => $preset->course,
                'year_level'        => $preset->year_level,
                'semester'          => $preset->semester,
                'lec_units'         => $preset->lec_units,
                'lab_units'         => $preset->lab_units,
                'lab_subject_count' => $preset->lab_subject_count,
                'has_nstp'          => $preset->has_nstp,
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

    /**
     * Link a subject to a preset with fee snapshot.
     *
     * Rates are locked at the current fee_settings values at time of linking.
     * The "Sync Fees" action can be used later to update them if rates change.
     */
    public function store(Request $request, CourseUnitPreset $preset)
    {
        $validated = $request->validate([
            'subject_id' => ['required', 'integer', 'exists:subjects,id'],
        ]);

        // Guard: prevent duplicates
        $alreadyLinked = CourseUnitPresetSubject::where('course_unit_preset_id', $preset->id)
            ->where('subject_id', $validated['subject_id'])
            ->exists();

        if ($alreadyLinked) {
            return back()->withErrors(['subject_id' => 'This subject is already linked to this preset.']);
        }

        $subject = Subject::findOrFail((int) $validated['subject_id']);

        $isNstp    = AssessmentService::isNstpSubject($subject->code, $subject->name);
        $isPathfit = AssessmentService::isPathfitSubject($subject->code, $subject->name);

        $rates = AssessmentService::loadRates();
        $fees  = AssessmentService::computeSubjectFees(
            $isNstp,
            $isPathfit,
            (int) $subject->lec_units,
            (int) $subject->lab_units,
            $rates
        );

        // sort_order: append after the current last row
        $maxSort = CourseUnitPresetSubject::where('course_unit_preset_id', $preset->id)
            ->max('sort_order') ?? 0;

        CourseUnitPresetSubject::create([
            'course_unit_preset_id' => $preset->id,
            'subject_id'            => $subject->id,
            'lec_units'             => (int) $subject->lec_units,
            'lab_units'             => (int) $subject->lab_units,
            'tuition_fee'           => $fees['tuition_fee'],
            'lab_fee'               => $fees['lab_fee'],
            'total_fee'             => $fees['total_fee'],
            'is_nstp'               => $isNstp,
            'is_pathfit'            => $isPathfit,
            'sort_order'            => $maxSort + 1,
        ]);

        // Update the preset's aggregate unit counts to match the linked subjects.
        $this->syncPresetAggregates($preset);

        return back()->with('success', 'Subject "' . $subject->code . ' — ' . $subject->name . '" added to preset.');
    }

    // ─── Destroy ──────────────────────────────────────────────────────────────

    /**
     * Remove a subject from the preset.
     */
    public function destroy(CourseUnitPreset $preset, CourseUnitPresetSubject $presetSubject)
    {
        if ($presetSubject->course_unit_preset_id !== $preset->id) {
            abort(404, 'Subject not found on this preset.');
        }

        $name = $presetSubject->code ?? 'Subject';
        $presetSubject->delete();

        // Re-sync aggregates after removal.
        $this->syncPresetAggregates($preset);

        return back()->with('success', 'Subject "' . $name . '" removed from preset.');
    }

    // ─── Sync ─────────────────────────────────────────────────────────────────

    /**
     * Recalculate all stored per-subject fees using the CURRENT fee_settings rates.
     *
     * Use this when tuition_per_unit or lab_fee_per_subject has changed and
     * the preset's stored fees need to reflect the new rates.
     *
     * This does NOT affect existing student assessments (those are immutable
     * once created). It only updates the preset reference data.
     */
    public function sync(CourseUnitPreset $preset)
    {
        $rates = AssessmentService::loadRates();

        DB::transaction(function () use ($preset, $rates) {
            $rows = CourseUnitPresetSubject::where('course_unit_preset_id', $preset->id)
                ->with('subject')
                ->get();

            foreach ($rows as $ps) {
                $isNstp    = AssessmentService::isNstpSubject($ps->code ?? '', $ps->subject?->name ?? '');
                $isPathfit = AssessmentService::isPathfitSubject($ps->code ?? '', $ps->subject?->name ?? '');

                $fees = AssessmentService::computeSubjectFees(
                    $isNstp,
                    $isPathfit,
                    (int) $ps->lec_units,
                    (int) $ps->lab_units,
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
     * Recalculate and persist the aggregate unit counts (lec_units, lab_units,
     * lab_subject_count, has_nstp) on the preset from its linked subjects.
     *
     * These aggregates are the values AssessmentService uses when creating an
     * assessment via a preset (the "no subjects in DB" fallback path). Keeping
     * them in sync ensures Create.vue shows correct auto-populated unit counts
     * even after subjects are added or removed from the preset.
     */
    private function syncPresetAggregates(CourseUnitPreset $preset): void
    {
        $subjects = CourseUnitPresetSubject::where('course_unit_preset_id', $preset->id)->get();

        $lecUnits        = 0;
        $labSubjectCount = 0;
        $hasNstp         = false;

        foreach ($subjects as $ps) {
            if ($ps->is_nstp) {
                $hasNstp = true;
                // NSTP lec_units intentionally excluded from billable aggregate —
                // AssessmentService handles NSTP billing separately at 1.5 fixed units.
                continue;
            }
            if ($ps->is_pathfit) {
                continue;
            }
            $lecUnits += (int) $ps->lec_units;
            if ((int) $ps->lab_units > 0) {
                $labSubjectCount++;
            }
        }

        $preset->update([
            'lec_units'         => $lecUnits,
            'lab_units'         => $labSubjectCount,   // lab_units on preset = count of lab subjects
            'lab_subject_count' => $labSubjectCount,
            'has_nstp'          => $hasNstp,
        ]);
    }
}