<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class ClinicVisit extends Model
{
    use SoftDeletes;
    protected $fillable = [
        'student_id',
        'faculty_id',
        'staff_id',
        'nurse_id',
        'department',
        'visit_date',
        'reason',
        'symptoms',
        'temperature',
        'blood_pressure',
        'assessment',
        'treatment',
        'medicine_id',
        'medicine_quantity',
        'remarks',
        'follow_up_date',
        'follow_up_status',
        'follow_up_notes',
    ];

    protected $casts = [
        'visit_date' => 'date:Y-m-d',
        'follow_up_date' => 'date:Y-m-d',
        'medicine_quantity' => 'integer',
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

    public function nurse()
    {
        return $this->belongsTo(User::class, 'nurse_id');
    }

    public function medicine()
    {
        return $this->belongsTo(Medicine::class);
    }
}