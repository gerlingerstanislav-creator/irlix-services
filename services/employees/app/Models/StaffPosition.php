<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class StaffPosition extends Model
{
    protected $table = 'staff_positions';
    protected $guarded = [];

    public function direction(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'direction_id');
    }

    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class, 'position_id');
    }
}
