<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class EmployeePermissionRule extends Model
{
    protected $table = 'employee_permission_rules';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['enabled' => 'boolean'];
    }
}
