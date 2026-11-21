<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Staff extends Model
{
    use SoftDeletes;
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

    protected $appends = [
        'employee_id',
    ];

    protected static function booted()
    {
        static::creating(function ($staff) {
            if (empty($staff->employee_id) && !empty($staff->staff_id)) {
                $staff->employee_id = $staff->staff_id;
            }
        });
        static::updating(function ($staff) {
            if (empty($staff->employee_id) && !empty($staff->staff_id)) {
                $staff->employee_id = $staff->staff_id;
            }
        });
    }

    public function getEmployeeIdAttribute()
    {
        return $this->attributes['staff_id'] ?? ($this->attributes['employee_id'] ?? null);
    }

    public function setEmployeeIdAttribute($value)
    {
        if (empty($this->attributes['staff_id']) && !empty($value)) {
            $this->attributes['staff_id'] = $value;
        }
        $this->attributes['employee_id'] = $value;
    }

    public function clinicVisits()
    {
        return $this->hasMany(ClinicVisit::class);
    }
}