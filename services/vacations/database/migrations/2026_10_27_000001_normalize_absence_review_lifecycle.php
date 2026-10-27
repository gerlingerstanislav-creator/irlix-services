<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        // Existing live workflow rows already have active approval tasks.
        // The stage now belongs exclusively to absence_approvals.
        DB::table('absences')
            ->whereIn('status', ['employee_review', 'hr_review', 'account_manager_review',
                'manager_review', 'hr_final_review'])
            ->whereExists(function ($q) {
                $q->selectRaw('1')->from('absence_approvals as step')
                    ->whereColumn('step.absence_id', 'absences.id')
                    ->where('step.status', 'pending');
            })
            ->update(['status' => 'in_review']);

        // Some older "on_approval" imports were incorrectly stored as
        // "planned" with every unapproved task marked "waiting". Repair only
        // untouched migration-owned records with an unambiguous first group.
        // Never synthesize approvers or timestamps and never alter user edits.
        DB::table('absences')->where('status', 'planned')
            ->where('created_by_subject', 'legacy-migration')
            ->whereNull('submitted_at')
            ->orderBy('id')->chunkById(200, function ($absences) {
                foreach ($absences as $absence) {
                    DB::transaction(function () use ($absence) {
                        $events = DB::table('absence_audit_log')
                            ->where('absence_id', $absence->id)->orderBy('id')->get(['event', 'after']);
                        if ($events->count() !== 1 || $events->first()->event !== 'legacy_migrated') return;
                        $source = json_decode((string) $events->first()->after, true);
                        if (mb_strtolower(trim((string) ($source['status'] ?? ''))) !== 'on_approval') return;

                        $approvals = DB::table('absence_approvals')
                            ->where('absence_id', $absence->id)->orderBy('sequence')->get(['sequence', 'status']);
                        if ($approvals->isEmpty() || $approvals->contains(fn ($task) => $task->status === 'pending')) return;
                        $waiting = $approvals->filter(fn ($task) => $task->status === 'waiting');
                        if ($waiting->isEmpty()) return;
                        $sequence = (int) $waiting->min('sequence');
                        DB::table('absence_approvals')->where('absence_id', $absence->id)
                            ->where('sequence', $sequence)->where('status', 'waiting')
                            ->update(['status' => 'pending']);
                        DB::table('absences')->where('id', $absence->id)->where('status', 'planned')
                            ->update(['status' => 'in_review']);
                    });
                }
            });
    }

    public function down(): void
    {
        // We cannot reconstruct a former duplicated stage from a lifecycle
        // value without potentially falsifying historical approval decisions.
        throw new RuntimeException('Vacations lifecycle normalization is intentionally irreversible; restore a verified checkpoint instead.');
    }
};
