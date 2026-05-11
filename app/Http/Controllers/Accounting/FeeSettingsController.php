<?php

namespace App\Http\Controllers\Accounting;

use App\Http\Controllers\Controller;
use App\Models\CourseUnitPreset;
use App\Models\FeeSetting;
use Illuminate\Http\Request;
use Inertia\Inertia;

class FeeSettingsController extends Controller
{
    public function index()
    {
        $settings = FeeSetting::where('is_active', true)
            ->orderByRaw("FIELD(category, 'rate', 'miscellaneous', 'other', 'term')")
            ->orderBy('sort_order')->orderBy('id')
            ->get()->groupBy('category')->toArray();

        $miscTotal = FeeSetting::whereIn('category', ['miscellaneous', 'other'])
            ->where('is_active', true)->sum('amount');

        $presets = CourseUnitPreset::where('is_active', true)
            ->orderBy('course')
            ->orderByRaw("FIELD(year_level, '1st Year', '2nd Year', '3rd Year', '4th Year', '5th Year')")
            ->orderByRaw("FIELD(semester, '1st Sem', '2nd Sem', 'Summer')")
            ->get()
            ->map(fn($p) => array_merge($p->toArray(), [
                // Include NSTP's fixed 1.5 billing units in the displayed total
                // when has_nstp is true. This matches how billing is calculated.
                'total_units' => $p->lec_units + $p->lab_units + ($p->has_nstp ? 1.5 : 0),
            ]))->toArray();

        $existingCourses = CourseUnitPreset::distinct()
            ->orderBy('course')
            ->pluck('course')
            ->values()
            ->toArray();

        return Inertia::render('Accounting/FeeSettings', [
            'settings'        => $settings,
            'miscTotal'       => round($miscTotal, 2),
            'presets'         => $presets,
            'existingCourses' => $existingCourses,
        ]);
    }

    public function update(Request $request, FeeSetting $feeSetting)
    {
        $validated = $request->validate([
            'amount' => ['required', 'numeric', 'min:0', 'max:99999.99'],
            'label'  => ['sometimes', 'string', 'max:100'],
        ]);

        if ($feeSetting->category === 'term') {
            $this->validateTermPercentages($feeSetting->key, (float) $validated['amount']);
        }

        $updateData = ['amount' => $validated['amount']];
        if (isset($validated['label'])) $updateData['label'] = $validated['label'];

        $feeSetting->update($updateData);
        return back()->with('success', "'{$feeSetting->label}' updated successfully.");
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'label'    => ['required', 'string', 'max:100'],
            'amount'   => ['required', 'numeric', 'min:0', 'max:99999.99'],
            'category' => ['required', 'in:miscellaneous,other'],
        ]);

        $key      = FeeSetting::generateKey($validated['label'], $validated['category']);
        $maxOrder = FeeSetting::where('category', $validated['category'])->max('sort_order') ?? 0;

        FeeSetting::create([
            'key'          => $key,
            'label'        => $validated['label'],
            'amount'       => $validated['amount'],
            'category'     => $validated['category'],
            'is_active'    => true,
            'sort_order'   => $maxOrder + 1,
            'is_deletable' => true,
        ]);

        return back()->with('success', "'{$validated['label']}' added to fee settings.");
    }

    public function destroy(FeeSetting $feeSetting)
    {
        if (!$feeSetting->is_deletable) {
            return back()->withErrors(['fee' => "'{$feeSetting->label}' is a system fee and cannot be removed."]);
        }
        if (in_array($feeSetting->category, ['rate', 'term'])) {
            return back()->withErrors(['fee' => 'Billing rates and payment terms cannot be deleted.']);
        }
        $label = $feeSetting->label;
        $feeSetting->delete();
        return back()->with('success', "'{$label}' removed from fee settings.");
    }

    public function bulkUpdate(Request $request)
    {
        $validated = $request->validate([
            'settings'          => 'required|array',
            'settings.*.id'     => 'required|integer|exists:fee_settings,id',
            'settings.*.amount' => 'required|numeric|min:0|max:99999.99',
        ]);

        $termUpdates = collect($validated['settings'])->filter(function ($item) {
            $setting = FeeSetting::find($item['id']);
            return $setting && $setting->category === 'term';
        });

        if ($termUpdates->isNotEmpty()) {
            $newTermAmounts = [];
            foreach ($validated['settings'] as $item) {
                $s = FeeSetting::find($item['id']);
                if ($s && $s->category === 'term') $newTermAmounts[$s->key] = (float) $item['amount'];
            }
            $total = 0;
            foreach (FeeSetting::where('category', 'term')->get() as $term) {
                $total += $newTermAmounts[$term->key] ?? (float) $term->amount;
            }
            if (abs($total - 100.00) > 0.01) {
                return back()->withErrors(['terms' => "Payment term percentages must sum to 100%. Current total: {$total}%"]);
            }
        }

        foreach ($validated['settings'] as $item) {
            FeeSetting::where('id', $item['id'])->update(['amount' => $item['amount']]);
        }
        return back()->with('success', 'Fee settings saved successfully.');
    }

    // ─── Course Unit Presets ───────────────────────────────────────────────────

    public function storePreset(Request $request)
    {
        $validated = $request->validate([
            'course'            => ['required', 'string', 'max:150'],
            'year_level'        => ['required', 'string', 'in:1st Year,2nd Year,3rd Year,4th Year,5th Year'],
            'semester'          => ['required', 'string', 'in:1st Sem,2nd Sem,Summer'],
            'lec_units'         => ['required', 'integer', 'min:0', 'max:30'],
            'lab_units'         => ['required', 'integer', 'min:0', 'max:30'],
            'lab_subject_count' => ['required', 'integer', 'min:0', 'max:15'],
            'has_nstp'          => ['boolean'],
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

        CourseUnitPreset::create(array_merge($validated, [
            'has_nstp'  => (bool) ($validated['has_nstp'] ?? false),
            'is_active' => true,
        ]));

        return back()->with('success', "Preset for {$validated['course']} {$validated['year_level']} {$validated['semester']} created.");
    }

    public function updatePreset(Request $request, CourseUnitPreset $preset)
    {
        $validated = $request->validate([
            'lec_units'         => ['required', 'integer', 'min:0', 'max:30'],
            'lab_units'         => ['required', 'integer', 'min:0', 'max:30'],
            'lab_subject_count' => ['required', 'integer', 'min:0', 'max:15'],
            'has_nstp'          => ['required', 'boolean'],
        ]);

        $preset->update($validated);
        return back()->with('success', "{$preset->course} {$preset->year_level} {$preset->semester} updated.");
    }

    public function destroyPreset(CourseUnitPreset $preset)
    {
        $label = "{$preset->course} {$preset->year_level} {$preset->semester}";
        $preset->update(['is_active' => false]);
        return back()->with('success', "Preset for {$label} deactivated.");
    }

    private function validateTermPercentages(string $updatedKey, float $newValue): void
    {
        $allTerms = FeeSetting::where('category', 'term')->get();
        $total = 0;
        foreach ($allTerms as $term) {
            $total += ($term->key === $updatedKey) ? $newValue : (float) $term->amount;
        }
        if (abs($total - 100.00) > 0.01) {
            abort(422, "Payment term percentages must sum to 100%. Current total: {$total}%");
        }
    }
}