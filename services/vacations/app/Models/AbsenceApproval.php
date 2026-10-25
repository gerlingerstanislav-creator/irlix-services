<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class AbsenceApproval extends Model
{
    protected $table = 'absence_approvals';
    protected $guarded = [];

    public function absence(): BelongsTo
    {
        return $this->belongsTo(Absence::class, 'absence_id');
    }
}
