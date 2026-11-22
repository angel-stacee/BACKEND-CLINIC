<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ClinicNotification extends Model
{
    protected $fillable = [
        'user_id',
        'category',
        'type',
        'title',
        'description',
        'metadata',
        'unique_key',
        'is_read',
        'read_at',
    ];

    protected $casts = [
        'metadata' => 'array',
        'is_read' => 'boolean',
        'read_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
