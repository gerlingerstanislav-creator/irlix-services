<?php
namespace App\Support;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
final class MemberTermHistory
{
    /** Original FK references survive a compatible merge; financial readers resolve aliases. */
    public static function approvedHours(int|array $periods): Collection
    {
        $rows=DB::table('reporting_period_approved_hours')->whereIn('reporting_period_id',(array)$periods)->get();
        if(!DB::getSchemaBuilder()->hasColumn('member_terms','superseded_by_term_id')) return $rows;
        $aliases=DB::table('member_terms')->whereIn('id',$rows->pluck('member_term_id'))
            ->whereNotNull('superseded_by_term_id')->pluck('superseded_by_term_id','id');
        return $rows->map(function($row)use($aliases){
            $row->original_member_term_id=$row->member_term_id;
            $row->member_term_id=$aliases[$row->member_term_id]??$row->member_term_id;
            return $row;
        });
    }
}
