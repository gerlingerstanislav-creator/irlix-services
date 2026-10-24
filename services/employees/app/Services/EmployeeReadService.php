<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\EmployeeStatusHistory;
use App\Models\EmploymentAssignmentHistory;
use App\Models\EmploymentPeriod;
use App\Models\SalaryHistory;
use Illuminate\Database\Eloquent\Builder;

final class EmployeeReadService
{
    public function employee(int $id): ?object
    {
        $row = $this->query()->where('employees.id', $id)->first();
        return $row ? (object) $row->getRawOriginal() : null;
    }

    public function record(int $id): ?Employee
    {
        return Employee::query()->whereKey($id)->first();
    }

    public function raw(int $id): ?object
    {
        $record = $this->record($id);
        return $record ? (object) $record->getRawOriginal() : null;
    }

    public function list(array $filters): array
    {
        $query = $this->query();
        if ($search = trim((string) ($filters['search'] ?? ''))) {
            $query->where(fn ($q) => $q
                ->where('employees.full_name', 'ilike', "%{$search}%")
                ->orWhere('employees.position', 'ilike', "%{$search}%")
                ->orWhere('employees.login', 'ilike', "%{$search}%"));
        }
        if ($departmentId = ($filters['department_id'] ?? null)) {
            $query->where('employees.department_id', (int) $departmentId);
        }
        if ($positionId = ($filters['position_id'] ?? null)) {
            $query->where('employees.position_id', (int) $positionId);
        }
        if ($status = trim((string) ($filters['employment_status'] ?? ''))) {
            $query->where('employees.employment_status', $status);
        }
        $rows = $query->orderBy('employees.full_name')->get()->map(
            static fn (Employee $row): object => (object) $row->getRawOriginal()
        );
        return ['data' => $rows, 'meta' => ['count' => $rows->count()]];
    }

    public function details(int $id): ?array
    {
        $employee = $this->employee($id);
        if (!$employee) return null;

        $periods = EmploymentPeriod::query()->leftJoin('departments',
                'departments.id', '=', 'employment_periods.department_id')
            ->select(['employment_periods.*', 'departments.name as department_name'])
            ->where('employment_periods.employee_id', $id)->orderByDesc('started_at')->get();
        $salaries = SalaryHistory::query()->where('employee_id', $id)->orderByDesc('effective_from')->get();
        $statuses = EmployeeStatusHistory::query()->where('employee_id', $id)->orderByDesc('effective_from')->get();
        $assignments = EmploymentAssignmentHistory::query()->from('employment_assignment_history as h')
            ->leftJoin('departments as d', 'd.id', '=', 'h.department_id')
            ->select(['h.*', 'd.name as department_name'])
            ->where('h.employee_id', $id)->orderByDesc('effective_from')->get();
        $raw = static fn ($items) => $items->map(static fn ($row) => (object) $row->getRawOriginal());

        return [
            'employee' => $employee,
            'employment_periods' => $raw($periods),
            'salary_history' => $raw($salaries),
            'status_history' => $raw($statuses),
            'assignment_history' => $raw($assignments),
        ];
    }

    public function loginSuggestions(string $firstName, string $lastName, string $requestedLogin): array
    {
        $first = $this->transliterate($firstName);
        $last = $this->transliterate($lastName);
        $candidates = ($first === '' || $last === '') ? [] : [
            "{$first}.{$last}",
            "{$last}.{$first}",
            mb_substr($first, 0, 1).".{$last}",
        ];
        $requested = $this->transliterate($requestedLogin);
        if ($requested !== '') array_unshift($candidates, $requested);
        $candidates = array_values(array_unique(array_filter($candidates)));
        $existing = Employee::query()->whereIn('login', $candidates)->pluck('login')->all();
        return array_map(static fn ($login) => [
            'login' => $login,
            'email' => "{$login}@irlix.ru",
            'available' => !in_array($login, $existing, true),
        ], $candidates);
    }

    private function transliterate(?string $value): string
    {
        $map = [
            'а'=>'a','б'=>'b','в'=>'v','г'=>'g','д'=>'d','е'=>'e','ё'=>'e','ж'=>'zh','з'=>'z','и'=>'i','й'=>'y','к'=>'k','л'=>'l','м'=>'m','н'=>'n','о'=>'o','п'=>'p','р'=>'r','с'=>'s','т'=>'t','у'=>'u','ф'=>'f','х'=>'h','ц'=>'ts','ч'=>'ch','ш'=>'sh','щ'=>'sch','ъ'=>'','ы'=>'y','ь'=>'','э'=>'e','ю'=>'yu','я'=>'ya',
        ];
        $value = mb_strtolower(trim((string) $value));
        $value = strtr($value, $map);
        $value = preg_replace('/[^a-z0-9]+/u', '.', $value) ?? '';
        return trim(preg_replace('/\.+/', '.', $value) ?? '', '.');
    }

    private function query(): Builder
    {
        return Employee::query()->leftJoin('departments',
            'departments.id', '=', 'employees.department_id')
            ->select(['employees.*', 'departments.name as department_name']);
    }
}
