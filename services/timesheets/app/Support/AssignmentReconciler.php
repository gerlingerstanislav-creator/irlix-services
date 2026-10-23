<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

final class AssignmentReconciler
{
    // Legacy project facts are preserved until a verified one-time conversion
    // to ClientMember facts. No reconciliation worker deletes hours or approvals.
    public function __construct(private readonly ClientMemberDirectory $directory) {}

    public function run(): void
    {
        $snapshot=$this->directory->snapshot(); // Fail closed if Clients is unavailable.
        $terms=$snapshot['members'];
        DB::transaction(function()use($terms):void {
            $entries=DB::table('timesheet_entries')
                ->whereNotNull('client_member_id')->orderBy('id')->lockForUpdate()->get();
            foreach($entries as $entry) {
                $candidate=array_values(array_filter($terms,static fn(array $t)=>
                    (int)$t['client_member_id']===(int)$entry->client_member_id &&
                    $t['valid_from']<=$entry->work_date &&
                    (empty($t['valid_to'])||$t['valid_to']>=$entry->work_date) &&
                    (($entry->employee_id!==null)
                        ? (int)($t['employee_id']??0)===(int)$entry->employee_id
                        : $t['partner_specialist_id']!==null)));
                $conflict=count($candidate)!==1;
                if((bool)$entry->term_conflict===$conflict) continue;
                DB::table('timesheet_entries')->where('id',$entry->id)
                    ->update(['term_conflict'=>$conflict,'updated_at'=>now()]);
                DB::table('timesheet_audit')->insert([
                    'action'=>$conflict?'client_member_term_conflict':'client_member_term_conflict_resolved',
                    'actor_employee_id'=>null,
                    'employee_id'=>$entry->employee_id,
                    'project_id'=>null,
                    'client_member_id'=>$entry->client_member_id,
                    'work_date'=>$entry->work_date,
                    'before'=>json_encode(['term_conflict'=>(bool)$entry->term_conflict]),
                    'after'=>json_encode(['term_conflict'=>$conflict]),
                    'created_at'=>now()
                ]);
            }
        });
    }
}
