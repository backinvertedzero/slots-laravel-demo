<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

class Slot extends Model
{
    use HasFactory;

    protected $fillable = [
        'capacity',
        'remaining'
    ];

    protected $casts = [
        'capacity' => 'integer',
        'remaining' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime'
    ];

    public function holds(): HasMany
    {
        return $this->hasMany(Hold::class);
    }

    public function hasAvailableCapacity(): bool
    {
        return $this->remaining > 0;
    }

    public function decrementRemaining(): bool
    {
        return DB::transaction(function () {
            // Атомарный декремент с проверкой
            $affected = DB::table('slots')
                ->where('id', $this->id)
                ->where('remaining', '>', 0)
                ->decrement('remaining');

            if ($affected) {
                $this->refresh();
                return true;
            }

            return false;
        });
    }

    public function incrementRemaining(): bool
    {
        return DB::transaction(function () {
            $affected = DB::table('slots')
                ->where('id', $this->id)
                ->where('remaining', '<', DB::raw('capacity'))
                ->increment('remaining');

            if ($affected) {
                $this->refresh();
                return true;
            }

            return false;
        });
    }

}