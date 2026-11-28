<?php

namespace App\Models;

use App\Models\Concerns\HasSoftDeleteUniqueToken;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Student extends Model
{
    use HasSoftDeleteUniqueToken, SoftDeletes;
    protected $fillable = [
        'student_id',
        'first_name',
        'middle_name',
        'last_name',
        'course',
        'year_level',
        'section',
        'sex',
        'birth_date',
        'contact_number',
        'address',
    ];

    protected $casts = [
        'birth_date' => 'date:Y-m-d',
    ];

    protected static function booted(): void
    {
        static::creating(function (Student $student) {
            if (empty($student->qr_token)) {
                $student->qr_token = self::generateQrToken();
            }
        });
    }

    public static function generateQrToken(): string
    {
        return 'TCMS-QR-'.bin2hex(random_bytes(12));
    }

    public function clinicVisits()
    {
        return $this->hasMany(ClinicVisit::class);
    }
}