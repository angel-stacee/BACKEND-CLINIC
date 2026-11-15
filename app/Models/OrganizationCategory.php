<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OrganizationCategory extends Model
{
    protected $primaryKey = 'category';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'category',
        'title',
        'tagline',
    ];

    public function members()
    {
        return $this->hasMany(OrganizationMember::class, 'category', 'category')
            ->orderBy('level')
            ->orderBy('sort_order')
            ->orderBy('id');
    }
}
