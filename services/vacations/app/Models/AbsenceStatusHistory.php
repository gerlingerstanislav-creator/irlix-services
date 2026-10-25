<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class AbsenceStatusHistory extends Model
{
    protected $table = 'absence_status_history';
    public $timestamps = false;
    protected $guarded = [];

    public function absence(): BelongsTo
    {
        return $this->belongsTo(Absence::class, 'absence_id');
    }
}
