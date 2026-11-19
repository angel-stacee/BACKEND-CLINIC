<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Staff extends Model
{
    protected $table = 'staff';

    protected $fillable = [
        'staff_id',
        'employee_id',
        'first_name',
        'middle_name',
        'last_name',
        'position',
        'department',
        'sex',
        'birth_date',
        'contact_number',
        'address',
    ];

    protected static function booted()
    {
        static::saving(function ($staff) {
            if (empty($staff->employee_id) && !empty($staff->staff_id)) {
                $staff->employee_id = $staff->staff_id;
            }
            if (empty($staff->staff_id) && !empty($staff->employee_id)) {
                $staff->staff_id = $staff->employee_id;
            }
        });
    }

    public function clinicVisits()
    {
        return $this->hasMany(ClinicVisit::class);
    }
}