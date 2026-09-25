<?php

namespace App\Domain\Absence;

enum AbsenceStatus: string
{
    case Planned = 'planned';
    case HrReview = 'hr_review';
    case AccountManagerReview = 'account_manager_review';
    case ManagerReview = 'manager_review';
    case HrFinalReview = 'hr_final_review';
    case Confirmed = 'confirmed';
    case Rejected = 'rejected';
    case Cancelled = 'cancelled';
}
