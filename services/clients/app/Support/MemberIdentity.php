<?php
namespace App\Support;
use Illuminate\Support\Facades\DB;
final class MemberIdentity {
    public static function field(object $member): string { return ($member->partner_specialist_id ?? null) !== null ? 'partner_specialist_id' : 'specialist_id'; }
    public static function assertConditionsAvailable(int $clientId, ?int $employeeId, ?int $partnerId, string $from, ?string $to, ?int $excludeTermId = null): void {
        $field = $partnerId !== null ? 'partner_specialist_id' : 'specialist_id';
        $id = $partnerId ?? $employeeId;
        abort_unless($id !== null && $id > 0, 422, 'Не определён специалист.');
        $query = DB::table('member_terms as mt')->join('project_members as pm', 'pm.id', '=', 'mt.project_member_id')->join('projects as p', 'p.id', '=', 'pm.project_id')
            ->where('p.client_id', $clientId)->where('pm.'.$field, $id)
            ->whereDate('mt.valid_from', '<=', $to ?? '9999-12-31')
            ->where(fn ($q) => $q->whereNull('mt.valid_to')->orWhereDate('mt.valid_to', '>=', $from));
        if ($excludeTermId !== null) $query->where('mt.id', '<>', $excludeTermId);
        abort_if($query->exists(), 422, 'У специалиста уже есть пересекающиеся условия работы у этого клиента.');
    }
}
