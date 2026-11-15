<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OrganizationMember extends Model
{
    protected $primaryKey = 'id';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'category',
        'name',
        'role',
        'department',
        'credentials',
        'level',
        'email',
        'phone',
        'location',
        'duty_hours',
        'status',
        'avatar_bg',
        'badge_color',
        'responsibilities',
        'reports_to',
        'sort_order',
    ];

    protected $casts = [
        'level' => 'integer',
        'sort_order' => 'integer',
        'responsibilities' => 'array',
    ];

    public function categoryRef()
    {
        return $this->belongsTo(OrganizationCategory::class, 'category', 'category');
    }

    /**
     * Map to format expected by React frontend.
     */
    public function toFrontendArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'role' => $this->role,
            'department' => $this->department,
            'credentials' => $this->credentials ?? '',
            'level' => (int) $this->level,
            'email' => $this->email ?? '',
            'phone' => $this->phone ?? '',
            'location' => $this->location ?? '',
            'dutyHours' => $this->duty_hours ?? '',
            'status' => $this->status ?? 'Active Duty',
            'avatarBg' => $this->avatar_bg ?? 'bg-[#70091f]',
            'badgeColor' => $this->badge_color ?? '',
            'responsibilities' => is_array($this->responsibilities) ? $this->responsibilities : [],
            'reportsTo' => $this->reports_to,
        ];
    }
}
