<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class SalaryReviewApproval extends Model
{
    protected $table = 'salary_review_approvals';
    protected $guarded = [];

    public function review(): BelongsTo
    {
        return $this->belongsTo(SalaryReview::class, 'review_id');
    }
}
