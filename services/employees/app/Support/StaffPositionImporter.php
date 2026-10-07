<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class StaffPositionImporter
{
    public function import(mixed $payload): array
    {
        if (!is_array($payload) || $payload === []) {
            throw new InvalidArgumentException('Файл должен содержать непустой JSON-массив должностей.');
        }
        if (count($payload) > 1000) {
            throw new InvalidArgumentException('За один импорт допускается не более 1000 должностей.');
        }

        $rows = [];
        $seen = [];
        foreach (array_values($payload) as $index => $row) {
            $number = $index + 1;
            if (!is_array($row)) {
                throw new InvalidArgumentException("Строка {$number}: ожидается объект.");
            }

            $department = trim((string) ($row['department'] ?? ''));
            $name = trim((string) ($row['name'] ?? ''));
            $salary = $row['base_salary'] ?? null;

            if ($department === '') {
                throw new InvalidArgumentException("Строка {$number}: не указано подразделение.");
            }
            if ($name === '') {
                throw new InvalidArgumentException("Строка {$number}: не указано название должности.");
            }
            if (mb_strlen($name) > 255) {
                throw new InvalidArgumentException("Строка {$number}: название должности длиннее 255 символов.");
            }
            if ($salary !== null && (!is_numeric($salary) || (float) $salary < 0)) {
                throw new InvalidArgumentException("Строка {$number}: некорректный оклад.");
            }

            $key = $department."\0".$name;
            if (isset($seen[$key])) {
                throw new InvalidArgumentException("Строка {$number}: должность «{$name}» в подразделении «{$department}» повторяется в файле.");
            }
            $seen[$key] = true;

            $rows[] = [
                'department' => $department,
                'name' => $name,
                'base_salary' => $salary === null ? null : round((float) $salary, 2),
            ];
        }

        $requestedDepartments = array_values(array_unique(array_column($rows, 'department')));
        $departmentRows = DB::table('departments')
            ->whereIn('name', $requestedDepartments)
            ->orWhereIn('alias', $requestedDepartments)
            ->get(['id', 'name', 'alias']);

        $departments = [];
        foreach ($departmentRows as $departmentRow) {
            foreach (array_filter([(string) $departmentRow->name, (string) ($departmentRow->alias ?? '')]) as $key) {
                if (isset($departments[$key]) && $departments[$key] !== (int) $departmentRow->id) {
                    throw new InvalidArgumentException("Подразделение «{$key}» неоднозначно: совпадает имя/алиас нескольких подразделений.");
                }
                $departments[$key] = (int) $departmentRow->id;
            }
        }

        foreach ($rows as $index => $row) {
            if (!isset($departments[$row['department']])) {
                $number = $index + 1;
                throw new InvalidArgumentException("Строка {$number}: подразделение «{$row['department']}» не найдено ни по названию, ни по алиасу.");
            }
        }

        return DB::transaction(function () use ($rows, $departments): array {
            $created = 0;
            $updated = 0;

            foreach ($rows as $row) {
                $departmentId = (int) $departments[$row['department']];
                $position = DB::table('staff_positions')
                    ->where('direction_id', $departmentId)
                    ->where('name', $row['name'])
                    ->lockForUpdate()
                    ->first();

                if ($position) {
                    DB::table('staff_positions')->where('id', $position->id)->update([
                        'base_salary' => $row['base_salary'],
                        'updated_at' => now(),
                    ]);
                    $updated++;
                    continue;
                }

                DB::table('staff_positions')->insert([
                    'name' => $row['name'],
                    'direction_id' => $departmentId,
                    'base_salary' => $row['base_salary'],
                    'closed_at' => null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $created++;
            }

            return [
                'total' => count($rows),
                'created' => $created,
                'updated' => $updated,
            ];
        });
    }
}
