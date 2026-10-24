<?php

namespace App\Services;

use App\Models\Department;
use App\Models\Employee;
use App\Models\EmploymentAssignmentHistory;
use App\Models\EmploymentPeriod;
use App\Models\StaffPosition;
use Illuminate\Support\Facades\DB;

final class StaffPositionService
{
    private function query(): \Illuminate\Database\Eloquent\Builder
    {
        return StaffPosition::query()->from('staff_positions as p')
            ->leftJoin('departments as d', 'd.id', '=', 'p.direction_id')
            ->select(['p.id', 'p.name', 'p.direction_id', 'd.name as direction_name',
                'p.base_salary', 'p.closed_at', 'p.created_at', 'p.updated_at'])
            ->selectSub(fn ($q) => $q->from('employees as position_employee')
                ->selectRaw('CAST(COUNT(*) AS INTEGER)')
                ->whereColumn('position_employee.position_id', 'p.id')
                ->whereColumn('position_employee.department_id', 'p.direction_id')
                ->where('position_employee.employment_status', 'Трудоустроен'), 'employee_count');
    }

    public function list(array $access): \Illuminate\Support\Collection
    {
        $roles = array_map(static fn ($role) => str_replace('_', '-', mb_strtolower((string) $role)),
            (array) ($access['roles'] ?? []));
        $admin = in_array('platform-admin', $roles, true);
        $accounting = (bool) ($access['staff_positions_salary_all'] ?? false);
        $managed = array_map('intval', (array) ($access['staff_positions_salary_departments'] ?? []));

        return $this->query()->orderByRaw('p.closed_at IS NOT NULL')
            ->orderBy('d.name')->orderBy('p.name')->get()
            ->map(static function (StaffPosition $position) use ($admin, $accounting, $managed): object {
                $row = (object) $position->getRawOriginal();
                $row->salary_visible = $admin || $accounting || in_array((int) $row->direction_id, $managed, true);
                if (!$row->salary_visible) $row->base_salary = null;
                return $row;
            });
    }

    public function existsDirection(int $direction): bool
    {
        return Department::query()->whereKey($direction)->exists();
    }

    public function find(int $position): ?object
    {
        $item = $this->query()->where('p.id', $position)->first();
        return $item ? (object) $item->getRawOriginal() : null;
    }

    public function findOriginal(int $position): ?StaffPosition
    {
        return StaffPosition::query()->whereKey($position)->first();
    }

    public function create(array $data): object
    {
        $position = StaffPosition::query()->create([
            'name' => trim($data['name']),
            'direction_id' => (int) $data['direction_id'],
            'base_salary' => $data['base_salary'] ?? null,
            'closed_at' => null,
        ]);
        return $this->find((int) $position->id);
    }

    public function update(int $position, array $data, StaffPosition $current): object
    {
        $newName = trim($data['name']);
        DB::transaction(static function () use ($position, $newName, $data, $current): void {
            StaffPosition::query()->whereKey($position)->update([
                'name' => $newName,
                'direction_id' => (int) $data['direction_id'],
                'base_salary' => array_key_exists('base_salary', $data)
                    ? $data['base_salary'] : $current->base_salary,
            ]);
            Employee::query()->where('position_id', $position)->update(['position' => $newName]);
        });
        return $this->find($position);
    }

    public function close(int $position): object
    {
        StaffPosition::query()->whereKey($position)->whereNull('closed_at')->update(['closed_at' => now()]);
        return $this->find($position);
    }

    public function reopen(int $position): object
    {
        StaffPosition::query()->whereKey($position)->whereNotNull('closed_at')->update(['closed_at' => null]);
        return $this->find($position);
    }

    /**
     * Returns result with previous 404/409 semantics for deletion.
     * Historical name snapshots are kept when detaching foreign keys.
     */
    public function delete(int $position): array
    {
        return DB::transaction(static function () use ($position): array {
            $current = StaffPosition::query()->whereKey($position)->lockForUpdate()->first();
            if (!$current) return ['status' => 404, 'message' => 'Должность не найдена.'];

            $active = Employee::query()->where('position_id', $position)
                ->where(fn ($q) => $q->whereNull('employment_status')
                    ->orWhere('employment_status', '<>', 'Уволен'))->count();
            if ($active > 0) return [
                'status' => 409,
                'message' => 'Нельзя удалить должность, на которой есть действующие сотрудники. Переведите сотрудников на другие должности или закройте эту должность.',
                'meta' => ['employee_count' => $active],
            ];

            foreach ([Employee::class, EmploymentPeriod::class, EmploymentAssignmentHistory::class] as $model) {
                $model::query()->where('position_id', $position)
                    ->whereNull('position')->update(['position' => $current->name]);
                $model::query()->where('position_id', $position)->update(['position_id' => null]);
            }
            $current->delete();
            return ['status' => 200, 'data' => [
                'id' => $position, 'name' => $current->name, 'deleted' => true,
            ]];
        });
    }
}
