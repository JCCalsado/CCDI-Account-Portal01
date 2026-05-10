<?php
namespace App\Http\Controllers\Accounting;

use App\Http\Controllers\Controller;
use App\Models\CurriculumFeePreset;
use Illuminate\Http\Request;

class CurriculumFeePresetController extends Controller
{
    public function index()
    {
        $presets = CurriculumFeePreset::orderBy('course')
            ->orderByRaw("FIELD(year_level,'1st Year','2nd Year','3rd Year','4th Year','5th Year')")
            ->orderByRaw("FIELD(semester,'1st Sem','2nd Sem','Summer')")
            ->get();
        return response()->json(['presets' => $presets]);
    }

    public function store(Request $request)
    {
        $v = $request->validate([
            'course'       => ['required','string','max:255'],
            'year_level'   => ['required','in:1st Year,2nd Year,3rd Year,4th Year,5th Year'],
            'semester'     => ['required','in:1st Sem,2nd Sem,Summer'],
            'lec_units'    => ['required','integer','min:0','max:60'],
            'lab_units'    => ['required','integer','min:0','max:60'],
            'lab_subjects' => ['required','integer','min:0','max:20'],
            'total_units'  => ['required','integer','min:0','max:120'],
            'has_nstp'     => ['boolean'],
        ]);

        $preset = CurriculumFeePreset::updateOrCreate(
            ['course'=>$v['course'],'year_level'=>$v['year_level'],'semester'=>$v['semester']],
            ['lec_units'=>$v['lec_units'],'lab_units'=>$v['lab_units'],'lab_subjects'=>$v['lab_subjects'],'total_units'=>$v['total_units'],'has_nstp'=>$v['has_nstp'] ?? false]
        );
        return response()->json(['ok'=>true,'preset'=>$preset]);
    }

    public function update(Request $request, CurriculumFeePreset $preset)
    {
        $v = $request->validate([
            'lec_units'    => ['required','integer','min:0','max:60'],
            'lab_units'    => ['required','integer','min:0','max:60'],
            'lab_subjects' => ['required','integer','min:0','max:20'],
            'total_units'  => ['required','integer','min:0','max:120'],
            'has_nstp'     => ['boolean'],
        ]);
        $preset->update($v);
        return response()->json(['ok'=>true,'preset'=>$preset]);
    }

    public function destroy(CurriculumFeePreset $preset)
    {
        $preset->delete();
        return response()->json(['ok'=>true]);
    }
}
