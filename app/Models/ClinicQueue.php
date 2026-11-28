<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClinicQueue extends Model
{
    protected $fillable = [
        'patient_type',
        'patient_id',
        'patient_identifier',
        'patient_name',
        'department',
        'priority',
        'status',
        'chief_complaint',
        'clinic_visit_id',
        'created_by',
        'checked_in_at',
        'called_at',
        'completed_at',
    ];

    protected $casts = [
        'checked_in_at' => 'datetime',
        'called_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function clinicVisit(): BelongsTo
    {
        return $this->belongsTo(ClinicVisit::class);
    }
}
