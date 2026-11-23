<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Medicine extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'medicine_name',
        'treatment_type',
        'expiration_date',
        'unit',
        'stock',
        'minimum_stock',
        'description',
    ];

    protected $casts = [
        'stock' => 'integer',
        'minimum_stock' => 'integer',
        'expiration_date' => 'date:Y-m-d',
    ];

    public function batches(): HasMany
    {
        return $this->hasMany(MedicineBatch::class);
    }

    public function activeBatches(): HasMany
    {
        return $this->hasMany(MedicineBatch::class)->where('current_quantity', '>', 0);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(MedicineTransaction::class);
    }

    /**
     * Recalculates total unexpired stock and nearest expiration date from batches
     */
    public function recalculateStock(): void
    {
        $today = now()->toDateString();

        $batches = $this->batches()->where('current_quantity', '>', 0)->get();

        if ($batches->isEmpty()) {
            $this->stock = 0;
            $this->save();
            return;
        }

        $unexpiredBatches = $batches->filter(function ($b) use ($today) {
            return ! $b->expiration_date || $b->expiration_date >= $today;
        });

        $this->stock = $unexpiredBatches->sum('current_quantity');

        // Set medicine expiration date to earliest unexpired batch expiration date
        $nearestUnexpired = $unexpiredBatches->sortBy('expiration_date')->first();
        if ($nearestUnexpired && $nearestUnexpired->expiration_date) {
            $this->expiration_date = $nearestUnexpired->expiration_date;
        } elseif ($batches->isNotEmpty()) {
            // If all are expired, reflect latest expiration date
            $this->expiration_date = $batches->sortByDesc('expiration_date')->first()?->expiration_date;
        }

        $this->save();
    }
}
