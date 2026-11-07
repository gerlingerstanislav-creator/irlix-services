<?php

namespace App\Services;

use App\Models\Department;
use App\Models\Employee;
use App\Support\EmployeesAccess;
use App\Models\SalaryReview;
use App\Models\SalaryReviewApproval;
use App\Models\SalaryReviewVersion;
use Illuminate\Support\Facades\DB;

final class SalaryReviewService
{
    public function scope(array $access, int $employee, string $permission = 'employees.fot.view'): bool
    {
        // Even full organizational scope never converts technical identities into staff.
        if (!Employee::query()->business()->whereKey($employee)->exists()) return false;
        $policy = EmployeesAccess::scopeFor($access, $permission);
        if (!($access['permissions'][$permission] ?? false)) return false;
        if ($policy['scope'] === 'all') return true;
        if ($policy['scope'] !== 'subtree') return false;
        $department = Employee::query()->whereKey($employee)->value('department_id');
        return $department !== null && in_array(
            (int) $department, array_map('intval', $policy['department_ids']), true
        );
    }

    public function review(int $id): ?SalaryReview
    {
        return SalaryReview::query()->whereKey($id)->first();
    }

    public function raw(int $id): ?object
    {
        $item = $this->review($id);
        return $item ? (object) $item->getRawOriginal() : null;
    }

    public function conflicts(int $employee, string $month, ?int $except = null): bool
    {
        return SalaryReview::query()->where('employee_id', $employee)
            ->whereDate('effective_month', $month)
            ->where('status', '<>', 'cancelled')
            ->when($except !== null, fn ($q) => $q->where('id', '<>', $except))->exists();
    }

    public function chain(int $employeeId, int $creator): array
    {
        $employee = Employee::query()->business()->whereKey($employeeId)->first();
        $departments = Department::query()->get()->keyBy('id');
        $cursor = (int) ($employee?->department_id ?? 0);
        $chain = [];
        $seen = [];
        $root = null;
        while ($cursor && isset($departments[$cursor]) && !isset($seen[$cursor])) {
            $seen[$cursor] = true;
            $department = $departments[$cursor];
            $manager = (int) ($department->manager_id ?? 0);
            if ($manager && $manager !== $creator && !in_array($manager, $chain, true)) {
                $chain[] = $manager;
            }
            $root = $department;
            $cursor = (int) ($department->parent_id ?? 0);
        }
        if ($cursor || !$root || $root->parent_id !== null
            || !in_array(mb_strtolower((string) $root->name), ['irlix', 'ирликс'], true)
            || (int) ($root->manager_id ?? 0) === 0
            || (int) $root->manager_id === $creator
            || end($chain) !== (int) $root->manager_id) {
            abort(409, 'Не задана полная цепочка до руководителя корневого подразделения');
        }
        return $chain;
    }

    public function list(array $access): array
    {
        $records = SalaryReview::query()->from('salary_reviews as r')
            ->join('employees as e', 'e.id', '=', 'r.employee_id')
            ->where('e.account_type', Employee::TYPE_EMPLOYEE)
            ->select('r.*', 'e.full_name as employee_name')
            ->orderByDesc('r.updated_at')->limit(500)->get();
        $visible = $records->filter(fn ($r) => $this->scope($access, (int) $r->employee_id))
            ->values();
        $ids = $visible->pluck('id')->all();

        $approvals = $ids ? SalaryReviewApproval::query()->from('salary_review_approvals as a')
            ->join('employees as e', 'e.id', '=', 'a.approver_id')
            ->whereIn('a.review_id', $ids)
            ->select('a.*', 'e.full_name as approver_name')
            ->orderBy('a.cycle')->orderBy('a.step')->get()
            ->groupBy('review_id') : collect();
        $versions = $ids ? SalaryReviewVersion::query()->whereIn('review_id', $ids)
            ->orderByDesc('created_at')->get()->groupBy('review_id') : collect();

        $raw = static fn ($items) => $items->map(
            static fn ($model) => (object) $model->getRawOriginal()
        )->values()->all();
        return $visible->map(static function ($row) use ($approvals, $versions, $raw): array {
            return [
                ...$row->getRawOriginal(),
                'approvals' => $raw($approvals->get($row->id) ?? collect()),
                'versions' => $raw($versions->get($row->id) ?? collect()),
            ];
        })->all();
    }

    public function inbox(int $approverId, array $access): \Illuminate\Support\Collection
    {
        return SalaryReviewApproval::query()->from('salary_review_approvals as a')
            ->join('salary_reviews as r', 'r.id', '=', 'a.review_id')
            ->join('employees as e', 'e.id', '=', 'r.employee_id')
            ->where('e.account_type', Employee::TYPE_EMPLOYEE)
            ->where('a.approver_id', $approverId)
            ->where('a.decision', 'waiting')
            ->where('r.status', 'pending')
            ->whereColumn('a.cycle', 'r.cycle')
            ->whereNotExists(function ($q): void {
                $q->selectRaw('1')->from('salary_review_approvals as prior')
                    ->whereColumn('prior.review_id', 'a.review_id')
                    ->whereColumn('prior.cycle', 'a.cycle')
                    ->whereColumn('prior.step', '<', 'a.step')
                    ->where('prior.decision', '<>', 'approved');
            })
            ->select('r.*', 'e.full_name as employee_name', 'a.id as approval_id')
            ->get()
            ->filter(fn ($row) => $this->scope($access, (int) $row->employee_id))
            ->values()->map(static fn ($row) => (object) $row->getRawOriginal());
    }

    public function create(array $data, int $actorId): object
    {
        $record = SalaryReview::query()->create([
            ...$data, 'created_by' => $actorId, 'status' => 'draft', 'cycle' => 0,
        ]);
        return $this->raw((int) $record->id);
    }

    public function edit(SalaryReview $item, array $data, int $actorId): object
    {
        DB::transaction(static function () use ($item, $data, $actorId): void {
            SalaryReviewVersion::query()->create([
                'review_id' => $item->id,
                'effective_month' => $item->effective_month,
                'gross_salary' => $item->gross_salary,
                'comment' => $item->comment,
                'status' => $item->status,
                'changed_by' => $actorId,
                'created_at' => now(),
            ]);
            SalaryReview::query()->whereKey($item->id)->update([
                ...$data, 'status' => 'draft',
            ]);
        });
        return $this->raw((int) $item->id);
    }

    public function submit(SalaryReview $item, array $chain): object
    {
        DB::transaction(static function () use ($item, $chain): void {
            $cycle = (int) $item->cycle + 1;
            SalaryReview::query()->whereKey($item->id)->update([
                'status' => 'pending', 'cycle' => $cycle,
            ]);
            foreach ($chain as $index => $manager) {
                SalaryReviewApproval::query()->create([
                    'review_id' => $item->id, 'cycle' => $cycle,
                    'step' => $index + 1, 'approver_id' => $manager,
                    'decision' => 'waiting',
                ]);
            }
        });
        return $this->raw((int) $item->id);
    }

    public function decide(int $reviewId, int $actorId, array $data): array
    {
        return DB::transaction(function () use ($reviewId, $actorId, $data): array {
            $item = SalaryReview::query()->whereKey($reviewId)->lockForUpdate()->first();
            if (!$item || $item->status !== 'pending') return [
                'status' => 409, 'message' => 'Нет активного согласования',
            ];
            $steps = SalaryReviewApproval::query()->where('review_id', $reviewId)
                ->where('cycle', $item->cycle)->orderBy('step')->get();
            $current = $steps->first(fn ($step) => $step->decision === 'waiting');
            if (!$current || (int) $current->approver_id !== $actorId) return [
                'status' => 403, 'message' => 'Сейчас не ваша очередь',
            ];
            if ($data['decision'] === 'returned' && trim((string) ($data['comment'] ?? '')) === '') {
                return ['status' => 422, 'message' => 'Комментарий обязателен'];
            }
            // Count pending steps before the model update. Eloquent mutates the
            // instance in $steps, unlike the old DB query's immutable stdClass.
            $waitingBeforeDecision = $steps->filter(
                fn ($step) => $step->decision === 'waiting'
            )->count();
            $current->update([
                'decision' => $data['decision'], 'comment' => $data['comment'] ?? null,
                'acted_at' => now(),
            ]);
            if ($data['decision'] === 'returned') {
                $item->update(['status' => 'returned']);
            } elseif ($waitingBeforeDecision === 1) {
                $item->update(['status' => 'approved']);
            }
            return ['status' => 200, 'data' => $this->raw($reviewId)];
        });
    }

    public function cancel(SalaryReview $item): object
    {
        $item->update(['status' => 'cancelled']);
        return $this->raw((int) $item->id);
    }
}
