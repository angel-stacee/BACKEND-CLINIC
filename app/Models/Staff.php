<?php

namespace App\Models;

use App\Models\Concerns\HasSoftDeleteUniqueToken;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Staff extends Model
{
    use HasSoftDeleteUniqueToken, SoftDeletes;
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
        'qr_token',
    ];

    public static function generateQrToken(): string
    {
        return 'TCMS-QR-' . bin2hex(random_bytes(12));
    }

    protected $appends = [
        'employee_id',
    ];

    protected static function booted()
    {
        static::creating(function ($staff) {
            if (empty($staff->attributes['employee_id']) && ! empty($staff->attributes['staff_id'])) {
                $staff->attributes['employee_id'] = $staff->attributes['staff_id'];
            }
            if (empty($staff->attributes['qr_token'])) {
                $staff->attributes['qr_token'] = self::generateQrToken();
            }
        });
        static::updating(function ($staff) {
            if (empty($staff->attributes['employee_id']) && ! empty($staff->attributes['staff_id'])) {
                $staff->attributes['employee_id'] = $staff->attributes['staff_id'];
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