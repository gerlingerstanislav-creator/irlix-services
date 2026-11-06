<?php

namespace App\Services;

use App\Models\SpecialistCompetency;
use App\Models\SpecialistProfile;
use App\Models\SpecialistTechnology;
use App\Models\SpecialistAdditionalTechnology;
use App\Models\SpecialistTimelineEvent;
use Illuminate\Support\Facades\DB;

final class EmployeePurgeService
{
    /** Only the authenticated platform-admin hard-delete coordinator calls this. */
    public function purge(int $employee, string $fullName = '', string $username = ''): void
    {
        DB::transaction(static function () use ($employee, $fullName, $username): void {
            SpecialistTimelineEvent::query()->where('employee_id', $employee)->delete();
            SpecialistTimelineEvent::query()->where('author_employee_id', $employee)
                ->update(['author_employee_id' => null, 'author' => null]);
            foreach (array_unique(array_filter([trim($fullName), trim($username)])) as $identity) {
                SpecialistTimelineEvent::query()->where('author', $identity)
                    ->update(['author_employee_id' => null, 'author' => null]);
            }
            SpecialistCompetency::query()->where('employee_id', $employee)->delete();
            SpecialistTechnology::query()->where('employee_id', $employee)->delete();
            SpecialistAdditionalTechnology::query()->where('employee_id', $employee)->delete();
            SpecialistProfile::query()->where('employee_id', $employee)->delete();
        });
    }
}
