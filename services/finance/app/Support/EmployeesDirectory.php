<?php

namespace App\Support;

use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class EmployeesDirectory
{
    public static function assertExists(int $employee): void
    {
        if($employee<1) throw new HttpException(422,'Некорректный идентификатор сотрудника');
        $token=(string)env('IRLIX_FINANCE_EMPLOYEES_TOKEN','');
        if(strlen($token)<32)throw new HttpException(503,'Контракт Employees не настроен');
        $url=rtrim((string)env('EMPLOYEES_FINANCE_DIRECTORY_URL',
            'http://employees:8000/internal/system/finance/employees'),'/').'/'.$employee;
        try {
            $response=Http::withHeaders(['X-Irlix-Finance-Employees-Token'=>$token])
                ->acceptJson()->timeout(8)->get($url);
        } catch(\Throwable) {
            throw new HttpException(503,'Сервис сотрудников недоступен');
        }
        if($response->status()===404)throw new HttpException(422,'Сотрудник не найден в Employees');
        if(!$response->successful() || (int)$response->json('data.id')!==$employee)
            throw new HttpException(503,'Не удалось подтвердить сотрудника через Employees');
    }
}
