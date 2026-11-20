<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Staff extends Model
{
    protected $table = 'staff';

    protected $fillable = [
        'staff_id',
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

    protected $appends = [
        'employee_id',
    ];

    public function getEmployeeIdAttribute()
    {
        return $this->attributes['staff_id'] ?? null;
    }

    public function setEmployeeIdAttribute($value)
    {
        if (empty($this->attributes['staff_id']) && !empty($value)) {
            $this->attributes['staff_id'] = $value;
        }
    }

    public function clinicVisits()
    {
        return $this->hasMany(ClinicVisit::class);
    }
}