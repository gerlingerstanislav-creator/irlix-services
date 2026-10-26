<?php
namespace App\Support;
use Illuminate\Support\Collection;
use App\Models\ReportingPeriodApprovedHours;
use App\Models\MemberTerm;
use Illuminate\Support\Facades\Schema;
final class MemberTermHistory
{
    /** Original FK references survive a compatible merge; financial readers resolve aliases. */
    public static function approvedHours(int|array $periods): Collection
    {
        $rows=ReportingPeriodApprovedHours::query()->whereIn('reporting_period_id',(array)$periods)->get()->map(fn ($model) => (object) $model->getRawOriginal());
        if(!Schema::hasColumn('member_terms','superseded_by_term_id')) return $rows;
        $aliases=MemberTerm::query()->whereIn('id',$rows->pluck('member_term_id'))
            ->whereNotNull('superseded_by_term_id')->pluck('superseded_by_term_id','id');
        return $rows->map(function($row)use($aliases){
            $row->original_member_term_id=$row->member_term_id;
            $row->member_term_id=$aliases[$row->member_term_id]??$row->member_term_id;
            return $row;
        });
    }
}
