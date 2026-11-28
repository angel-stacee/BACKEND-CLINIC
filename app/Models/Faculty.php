<?php

namespace App\Models;

use App\Models\Concerns\HasSoftDeleteUniqueToken;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Faculty extends Model
{
    use HasSoftDeleteUniqueToken, SoftDeletes;
    protected $fillable = [
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

    protected static function booted(): void
    {
        static::creating(function (Faculty $faculty) {
            if (empty($faculty->qr_token)) {
                $faculty->qr_token = self::generateQrToken();
            }
        });
    }

    public static function generateQrToken(): string
    {
        return 'TCMS-QR-' . bin2hex(random_bytes(12));
    }

    protected $casts = [
        'birth_date' => 'date:Y-m-d',
    ];

    public function clinicVisits()
    {
        return $this->hasMany(ClinicVisit::class);
    }
}