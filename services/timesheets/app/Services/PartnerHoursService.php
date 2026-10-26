<?php

namespace App\Services;

use App\Models\FinalApproval;
use App\Models\FinalApprovalException;
use App\Models\PartnerDailyConfirmation;
use App\Models\TimesheetAudit;
use App\Models\TimesheetEntry;
use App\Support\PartnerDayLock;
use App\Support\ReportingFreeze;
use App\Support\TimeHours;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class PartnerHoursService
{
    public function __construct(private readonly PartnerAccessService $access) {}

    private function findTerm(array $rows, int $id, string $date): array
    {
        $terms = array_values(array_filter($rows, static fn ($m) =>
            (int) $m['client_member_id'] === $id && $m['valid_from'] !== null
            && $m['valid_from'] <= $date && (empty($m['valid_to']) || $m['valid_to'] >= $date)
        ));
        abort_if(count($terms) !== 1, 422, 'Нет однозначных коммерческих условий на выбранную дату.');
        return $terms[0];
    }

    private function checkClientLocks(array $locks, int $clientId, string $date): void
    {
        foreach ($locks as $period) {
            if ((int) $period['client_id'] === $clientId
                && $period['period_start'] <= $date && $period['period_end'] >= $date) {
                abort(423, 'Отчётный период передан клиенту и заблокирован.');
            }
        }
    }

    /** Raw attributes prevent Eloquent casts from changing the public JSON contract. */
    private function rawRows($query)
    {
        return $query->get()->map(static fn ($row) => (object) $row->getRawOriginal());
    }

    public function calendar(Request $request, string $month): array
    {
        [, $rows, $locks] = $this->access->resolve($request);
        $from = $month.'-01';
        $to = Carbon::parse($from)->endOfMonth()->toDateString();
        $active = array_values(array_filter($rows, static fn ($m) =>
            $m['valid_from'] !== null && $m['valid_from'] <= $to
            && (empty($m['valid_to']) || $m['valid_to'] >= $from)
        ));
        $ids = array_values(array_unique(array_column($rows, 'client_member_id')));
        $ids = $ids ?: [0];
        return [
            'month' => $month,
            'members' => $active,
            'locked_reporting_periods' => $locks,
            'entries' => $this->rawRows(TimesheetEntry::query()
                ->whereIn('client_member_id', $ids)->whereBetween('work_date', [$from, $to])),
            'confirmations' => $this->rawRows(PartnerDailyConfirmation::query()
                ->whereIn('client_member_id', $ids)->whereBetween('work_date', [$from, $to])),
            'final_approvals' => $this->rawRows(FinalApproval::query()
                ->whereIn('client_member_id', $ids)->where('month', $from)),
            'final_approval_exceptions' => $this->rawRows(FinalApprovalException::query()
                ->whereIn('client_member_id', $ids)->whereBetween('work_date', [$from, $to])),
        ];
    }

    public function write(Request $request, array $data): void
    {
        [$me, $rows, $locks, $allMembers] = $this->access->resolve($request);
        $id = (int) $data['client_member_id'];
        $date = $data['work_date'];
        $member = $this->findTerm($rows, $id, $date);
        $this->checkClientLocks($locks, (int) $member['client_id'], $date);
        $hours = TimeHours::decimal($data['hours']);
        $month = Carbon::parse($date)->startOfMonth()->toDateString();
        DB::transaction(function () use ($id, $date, $hours, $data, $me, $month, $allMembers, $member): void {
            $partnerId = (int) $member['partner_specialist_id'];
            PartnerDayLock::acquire($partnerId, $date);
            ReportingFreeze::assertEditable((int) $member['client_id'], $date);
            $approved = FinalApproval::query()->where('client_member_id', $id)->where('month', $month)->exists();
            $linkedIds = array_values(array_unique(array_map('intval', array_column(array_values(array_filter(
                $allMembers, static fn ($v) => (int) ($v['partner_specialist_id'] ?? 0) === $partnerId
            )), 'client_member_id'))));
            $others = TimesheetEntry::query()->whereIn('client_member_id', $linkedIds ?: [0])
                ->where('work_date', $date)->where('client_member_id', '<>', $id)->sum('hours');
            abort_if(TimeHours::cents($others) + TimeHours::cents($hours) > 2400,
                422, 'Более 24 часов в день у партнёра.');
            $existing = TimesheetEntry::query()->where('client_member_id', $id)
                ->where('work_date', $date)->lockForUpdate()->first();
            $description = trim((string) ($data['description'] ?? '')) ?: null;
            TimeHours::assertWritable($hours, $existing?->hours);
            if ($existing && TimeHours::cents($existing->hours) === TimeHours::cents($hours)
                && $existing->description === $description) return;
            $payload = [
                'employee_id' => null, 'project_id' => null,
                'client_member_id' => $id, 'work_date' => $date,
                'hours' => $hours, 'description' => $description,
                'term_conflict' => false, 'updated_at' => now(),
            ];
            if ($existing) {
                TimesheetEntry::query()->where('id', $existing->id)->update($payload);
            } else {
                TimesheetEntry::query()->insert([...$payload, 'created_at' => now()]);
            }
            PartnerDailyConfirmation::query()->where('client_member_id', $id)
                ->where('work_date', $date)->delete();
            if ($approved) {
                FinalApprovalException::query()->updateOrInsert([
                    'client_member_id' => $id, 'work_date' => $date,
                ], [
                    'employee_id' => null, 'project_id' => null,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
            TimesheetAudit::query()->insert([
                'action' => $existing ? 'partner_entry_updated' : 'partner_entry_created',
                'actor_employee_id' => (int) $me['id'],
                'employee_id' => null, 'project_id' => null,
                'client_member_id' => $id, 'work_date' => $date,
                'before' => $existing ? json_encode($existing->getRawOriginal(), JSON_UNESCAPED_UNICODE) : null,
                'after' => json_encode($payload, JSON_UNESCAPED_UNICODE), 'created_at' => now(),
            ]);
        });
    }

    public function confirm(Request $request, array $data): void
    {
        [$me, $rows, $locks] = $this->access->resolve($request);
        $id = (int) $data['client_member_id'];
        $date = $data['work_date'];
        $member = $data['confirmed'] ? $this->findTerm($rows, $id, $date)
            : collect($rows)->firstWhere('client_member_id', $id);
        abort_unless($member, 422, 'Подключение партнёра недоступно.');
        $this->checkClientLocks($locks, (int) $member['client_id'], $date);
        DB::transaction(function () use ($id, $date, $data, $me, $member): void {
            PartnerDayLock::acquire((int) $member['partner_specialist_id'], $date);
            ReportingFreeze::assertEditable((int) $member['client_id'], $date);
            $month = Carbon::parse($date)->startOfMonth()->toDateString();
            $final = FinalApproval::query()->where('client_member_id', $id)
                ->where('month', $month)->exists();
            $exception = FinalApprovalException::query()->where('client_member_id', $id)
                ->where('work_date', $date)->exists();
            if (!$data['confirmed'] && $final && !$exception) abort(423, 'Сначала снимите финальное подтверждение.');
            $entry = TimesheetEntry::query()->where('client_member_id', $id)->where('work_date', $date)->first();
            abort_if($data['confirmed'] && $entry && ((bool) $entry->term_conflict || $entry->employee_id !== null),
                422, 'Конфликт личности или условий ТШ.');
            $before = PartnerDailyConfirmation::query()->where('client_member_id', $id)
                ->where('work_date', $date)->first();
            if ($data['confirmed']) {
                PartnerDailyConfirmation::query()->updateOrInsert([
                    'client_member_id' => $id, 'work_date' => $date,
                ], [
                    'confirmed_at' => now(), 'confirmed_by_employee_id' => (int) $me['id'],
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            } else {
                PartnerDailyConfirmation::query()->where('client_member_id', $id)
                    ->where('work_date', $date)->delete();
            }
            TimesheetAudit::query()->insert([
                'action' => $data['confirmed'] ? 'partner_day_confirmed' : 'partner_day_unconfirmed',
                'actor_employee_id' => (int) $me['id'],
                'employee_id' => null, 'client_member_id' => $id, 'project_id' => null,
                'work_date' => $date,
                'before' => $before ? json_encode($before->getRawOriginal()) : null,
                'after' => json_encode(['confirmed' => $data['confirmed']]), 'created_at' => now(),
            ]);
        });
    }

    public function finalApproval(Request $request, array $data): void
    {
        [$me, $rows, $locks] = $this->access->resolve($request);
        $id = (int) $data['client_member_id'];
        $from = $data['month'].'-01';
        $to = Carbon::parse($from)->endOfMonth()->toDateString();
        $terms = array_values(array_filter($rows, static fn ($m) =>
            (int) $m['client_member_id'] === $id
            && $m['valid_from'] !== null && $m['valid_from'] <= $to
            && (empty($m['valid_to']) || $m['valid_to'] >= $from)
        ));
        $member = collect($rows)->firstWhere('client_member_id', $id);
        abort_unless($member, 422, 'Подключение партнёра недоступно.');
        abort_if($data['approved'] && !$terms, 422, 'Нет подключения партнёра в указанном месяце.');
        for ($day = Carbon::parse($from); $day->lte(Carbon::parse($to)); $day->addDay()) {
            $this->checkClientLocks($locks, (int) $member['client_id'], $day->toDateString());
        }
        DB::transaction(function () use ($id, $from, $to, $data, $me, $member, $rows): void {
            for ($day = Carbon::parse($from); $day->lte(Carbon::parse($to)); $day->addDay()) {
                PartnerDayLock::acquire((int) $member['partner_specialist_id'], $day->toDateString());
            }
            ReportingFreeze::assertEditable((int) $member['client_id'], $from, $to);
            if ($data['approved']) {
                $actual = TimesheetEntry::query()->where('client_member_id', $id)
                    ->whereBetween('work_date', [$from, $to])->get();
                foreach ($actual as $entry) {
                    $this->findTerm($rows, $id, substr((string) $entry->work_date, 0, 10));
                    abort_if((bool) $entry->term_conflict || $entry->employee_id !== null,
                        422, 'Не разрешён конфликт периода или личности.');
                    abort_unless(PartnerDailyConfirmation::query()->where('client_member_id', $id)
                        ->where('work_date', $entry->work_date)->exists(),
                        422, 'Не подтверждены все заполненные дни партнёра.');
                }
            }
            $before = FinalApproval::query()->where('client_member_id', $id)->where('month', $from)->first();
            if ($data['approved']) {
                FinalApproval::query()->updateOrInsert([
                    'client_member_id' => $id, 'month' => $from,
                ], [
                    'employee_id' => null, 'project_id' => null, 'approved_by' => (int) $me['id'],
                    'approved_at' => now(), 'created_at' => now(), 'updated_at' => now(),
                ]);
            } else {
                FinalApproval::query()->where('client_member_id', $id)->where('month', $from)->delete();
            }
            FinalApprovalException::query()->where('client_member_id', $id)
                ->whereBetween('work_date', [$from, $to])->delete();
            TimesheetAudit::query()->insert([
                'action' => $data['approved'] ? 'partner_final_approved' : 'partner_final_unapproved',
                'actor_employee_id' => (int) $me['id'], 'employee_id' => null,
                'client_member_id' => $id, 'project_id' => null,
                'work_date' => null,
                'before' => $before ? json_encode($before->getRawOriginal()) : null,
                'after' => json_encode(['month' => $from, 'approved' => $data['approved']]),
                'created_at' => now(),
            ]);
        });
    }
}
