<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class AbsenceAttachment extends Model
{
    protected $table = 'absence_attachments';
    protected $guarded = [];

    public function absence(): BelongsTo
    {
        return $this->belongsTo(Absence::class, 'absence_id');
    }
}
