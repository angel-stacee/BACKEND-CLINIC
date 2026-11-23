<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class MedicineBatch extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'medicine_id',
        'batch_number',
        'initial_quantity',
        'current_quantity',
        'date_added',
        'expiration_date',
        'supplier',
        'remarks',
    ];

    protected $casts = [
        'initial_quantity' => 'integer',
        'current_quantity' => 'integer',
        'date_added' => 'date:Y-m-d',
        'expiration_date' => 'date:Y-m-d',
    ];

    protected $appends = [
        'status',
        'days_until_expiration',
    ];

    public function medicine(): BelongsTo
    {
        return $this->belongsTo(Medicine::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(MedicineTransaction::class);
    }

    /**
     * Scope to find unexpired batches with remaining stock
     */
    public function scopeAvailable(Builder $query): Builder
    {
        $today = now()->toDateString();
        return $query->where('current_quantity', '>', 0)
            ->where(function ($q) use ($today) {
                $q->whereNull('expiration_date')->orWhere('expiration_date', '>=', $today);
            });
    }

    /**
     * Scope for FEFO (First Expired, First Out) ordering,
     * with secondary FIFO (First In, First Out) ordering.
     */
    public function scopeFefo(Builder $query): Builder
    {
        return $query->orderBy('expiration_date', 'asc')
            ->orderBy('date_added', 'asc')
            ->orderBy('id', 'asc');
    }

    /**
     * 4-Tier Expiration Status
     * - Expired: Already past the expiration date (< today)
     * - Expiring Soon: Will expire within the next 30 days (0-30 days)
     * - Near Expiration: Will expire within 31-90 days
     * - Good: More than 90 days before expiration
     */
    public function getStatusAttribute(): string
    {
        if (! $this->expiration_date) {
            return 'Good';
        }

        $today = now()->startOfDay();
        $expDate = Carbon::parse($this->expiration_date)->startOfDay();
        $days = (int) $today->diffInDays($expDate, false);

        if ($days < 0) {
            return 'Expired';
        }
        if ($days <= 30) {
            return 'Expiring Soon';
        }
        if ($days <= 90) {
            return 'Near Expiration';
        }

        return 'Good';
    }

    public function getDaysUntilExpirationAttribute(): ?int
    {
        if (! $this->expiration_date) {
            return null;
        }

        $today = now()->startOfDay();
        $expDate = Carbon::parse($this->expiration_date)->startOfDay();

        return (int) $today->diffInDays($expDate, false);
    }
}
