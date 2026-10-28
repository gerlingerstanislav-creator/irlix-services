<?php

namespace App\Services;

use App\Models\Department;
use App\Models\Employee;

final class StorageV1DirectoryService
{
    /** Only safe recipient lookup columns; no salary, contacts, or other HR data. */
    public function people(): array
    {
        return Employee::query()
            ->whereNotNull('keycloak_user_id')
            ->where(function ($query) {
                $query->where('employment_status', 'Трудоустроен')
                    ->orWhere('login', 'admin');
            })
            ->orderBy('full_name')
            ->get(['id','full_name','department_id'])
            ->map(static fn ($row) => [
                'id' => (int) $row->id,
                'full_name' => (string) $row->full_name,
                'department_id' => $row->department_id ? (int) $row->department_id : null,
            ])->all();
    }

    public function departments(): array
    {
        return Department::query()->orderBy('name')
            ->get(['id','name','parent_id'])
            ->map(static fn ($row) => [
                'id' => (int) $row->id,
                'name' => (string) $row->name,
                'parent_id' => $row->parent_id ? (int) $row->parent_id : null,
            ])->all();
    }
}
