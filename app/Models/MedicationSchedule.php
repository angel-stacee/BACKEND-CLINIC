<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class MedicationSchedule extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'student_id',
        'faculty_id',
        'staff_id',
        'patient_type',
        'patient_name',
        'medicine_id',
        'nurse_id',
        'dosage',
        'frequency',
        'scheduled_time',
        'start_date',
        'end_date',
        'instructions',
        'status',
    ];

    protected $casts = [
        'start_date' => 'date:Y-m-d',
        'end_date' => 'date:Y-m-d',
    ];

    public function student()
    {
        return $this->belongsTo(Student::class);
    }

    public function faculty()
    {
        return $this->belongsTo(Faculty::class);
    }

    public function staff()
    {
        return $this->belongsTo(Staff::class);
    }

    public function medicine()
    {
        return $this->belongsTo(Medicine::class);
    }

    public function nurse()
    {
        return $this->belongsTo(User::class, 'nurse_id');
    }
}
