<?php
namespace App\Support;
use Illuminate\Support\Facades\DB;
final class MemberIdentity {
    public static function field(object $member): string { return ($member->partner_specialist_id ?? null) !== null ? 'partner_specialist_id' : 'specialist_id'; }
    public static function assertConditionsAvailable(int $clientId, ?int $employeeId, ?int $partnerId, string $from, ?string $to, ?int $excludeTermId = null): void {
        $field = $partnerId !== null ? 'partner_specialist_id' : 'employee_id';
        $id = $partnerId ?? $employeeId;
        abort_unless($id !== null && $id > 0, 422, 'Не определён специалист.');
        // All writers serialize on the enduring membership, including legacy routes.
        $member = DB::table('client_members')->where('client_id',$clientId)->where($field,$id)->lockForUpdate()->first();
        if (!$member) return;
        $query = DB::table('member_terms')->where('client_member_id',$member->id)
            ->whereDate('valid_from', '<=', $to ?? '9999-12-31')
            ->where(fn ($q) => $q->whereNull('valid_to')->orWhereDate('valid_to', '>=', $from));
        if ($excludeTermId !== null) $query->where('id', '<>', $excludeTermId);
        abort_if($query->exists(), 422, 'У специалиста уже есть пересекающиеся условия работы у этого клиента.');
    }
    public static function canonicalTermId(int $id): int {
        $term=DB::table('member_terms')->find($id);
        abort_unless($term,404,'Коммерческие условия не найдены.');
        return (int)($term->superseded_by_term_id??$id);
    }
    public static function legacyMember(int $id): object {
        $old=DB::table('project_members as pm')->join('projects as p','p.id','=','pm.project_id')
            ->where('pm.id',$id)->select('pm.*','p.client_id')->first();
        abort_unless($old,404,'Подключение не найдено.');
        $identity=['client_id'=>$old->client_id, $old->partner_specialist_id!==null?'partner_specialist_id':'employee_id'=>$old->partner_specialist_id??$old->specialist_id];
        $member=DB::table('client_members')->where($identity)->first();
        abort_unless($member,409,'Отсутствует клиентское подключение.');
        return $member;
    }
    public static function assertTermChangeEditable(int $clientId,object $old,array $changes,string $from,?string $to): void {
        $commercialChanged=false;
        foreach(['technology','level','hourly_rate','hours_per_day']as $field) {
            if(!array_key_exists($field,$changes)) continue;
            $value=$changes[$field];
            $same=($value===null || $old->$field===null)
                ? $value===$old->$field
                : (in_array($field,['hourly_rate','hours_per_day'],true)
                    ? (float)$value===(float)$old->$field : (string)$value===(string)$old->$field);
            if(!$same) $commercialChanged=true;
        }
        $periods=DB::table('reporting_periods')->where('client_id',$clientId)->where('status','<>','Новый')->get();
        foreach($periods as $period) {
            $clip=static function(string $start,?string $end)use($period):?array{
                $a=max(substr((string)$period->period_start,0,10),substr($start,0,10));
                $b=min(substr((string)$period->period_end,0,10),$end===null?'9999-12-31':substr($end,0,10));
                return $a<=$b?[$a,$b]:null;
            };
            $before=$clip((string)$old->valid_from,$old->valid_to);
            $after=$clip($from,$to);
            abort_if($before!==$after || ($commercialChanged && ($before!==null || $after!==null)),423,'Нельзя менять условия отправленного отчётного периода.');
        }
    }
    public static function assertPeriodEditable(int $clientId,string $from,?string $to): void {
        abort_if(DB::table('reporting_periods')->where('client_id',$clientId)->where('status','<>','Новый')
            ->whereDate('period_start','<=',$to??'9999-12-31')->whereDate('period_end','>=',$from)->exists(),
            423,'Нельзя менять условия отправленного отчётного периода.');
    }
}
