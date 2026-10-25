<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class SalaryReview extends Model
{
    protected $table = 'salary_reviews';
    protected $guarded = [];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }

    public function approvals(): HasMany
    {
        return $this->hasMany(SalaryReviewApproval::class, 'review_id');
    }
}
