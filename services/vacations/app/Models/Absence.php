<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class Absence extends Model
{
    protected $table = 'absences';
    protected $guarded = [];

    public function approvals(): HasMany
    {
        return $this->hasMany(AbsenceApproval::class, 'absence_id');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(AbsenceAttachment::class, 'absence_id');
    }

    public function statusHistory(): HasMany
    {
        return $this->hasMany(AbsenceStatusHistory::class, 'absence_id');
    }
}
