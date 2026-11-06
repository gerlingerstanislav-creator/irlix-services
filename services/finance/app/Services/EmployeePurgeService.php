<?php

namespace App\Services;

use App\Support\EmployeePurgeRepository;
use Illuminate\Support\Facades\DB;

final class EmployeePurgeService
{
    public function __construct(private readonly EmployeePurgeRepository $repository) {}

    public function purge(int $employee, string $username, string $fullName): void
    {
        DB::transaction(fn () => $this->repository->erase($employee,
            [trim($username), trim($fullName)], trim($fullName)));
    }
}
