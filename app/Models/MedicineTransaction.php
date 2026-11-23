<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MedicineTransaction extends Model
{
    use HasFactory;

    protected $fillable = [
        'medicine_id',
        'medicine_batch_id',
        'clinic_visit_id',
        'transaction_type', // stock_in, dispense, return, adjustment, discard_expired
        'quantity',
        'batch_number',
        'expiration_date',
        'issued_to_type', // student, faculty, staff, walk-in
        'issued_to_id',
        'issued_to_name',
        'performed_by',
        'notes',
    ];

    protected $casts = [
        'quantity' => 'integer',
        'expiration_date' => 'date:Y-m-d',
    ];

    public function medicine(): BelongsTo
    {
        return $this->belongsTo(Medicine::class);
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(MedicineBatch::class, 'medicine_batch_id');
    }

    public function clinicVisit(): BelongsTo
    {
        return $this->belongsTo(ClinicVisit::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'performed_by');
    }
}
