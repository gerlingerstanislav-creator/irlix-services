<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Services\EmployeeIdentityService;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

final class EmployeesSystemController extends Controller
{
    public function __construct(private readonly EmployeeIdentityService $identity) {}

    public function health(): JsonResponse
    {
        return response()->json([
            'service' => 'employees',
            'status' => 'ok',
            'database' => Employee::query()->getConnection()->select('select 1') ? 'ok' : 'error',
            'identity' => $this->identity->health(),
        ]);
    }

    public function reference(): JsonResponse
    {
        return response()->json(['data' => [
            'employee_statuses' => ['Ожидает трудоустройства', 'Трудоустроен', 'Уволен'],
            'work_formats' => ['Офис', 'Удалённо'],
            'cooperation_types' => ['Штат', 'ГПХ', 'ИП', 'Самозанятый'],
            'genders' => ['Мужчина', 'Женщина'],
        ]]);
    }
}
