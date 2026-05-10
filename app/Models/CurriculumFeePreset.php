<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CurriculumFeePreset extends Model
{
    protected $table    = 'curriculum_fee_presets';
    protected $fillable = ['course','year_level','semester','lec_units','lab_units','lab_subjects','total_units','has_nstp'];
    protected $casts    = ['lec_units'=>'integer','lab_units'=>'integer','lab_subjects'=>'integer','total_units'=>'integer','has_nstp'=>'boolean'];

    public static function findPreset(string $course, string $yearLevel, string $semester): ?self
    {
        return self::where('course', $course)->where('year_level', $yearLevel)->where('semester', $semester)->first();
    }
}
