<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MedicineRequisition extends Model
{
    use HasFactory;

    protected $fillable = [
        'medicine_id',
        'quantity_requested',
        'unit',
        'priority',
        'reason',
        'status',
        'requested_by',
        'requester_name',
        'date_requested',
        'remarks',
        'recipient_email',
        'email_sent',
    ];

    protected $casts = [
        'quantity_requested' => 'integer',
        'date_requested' => 'date:Y-m-d',
        'email_sent' => 'boolean',
    ];

    public function medicine(): BelongsTo
    {
        return $this->belongsTo(Medicine::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }
}
