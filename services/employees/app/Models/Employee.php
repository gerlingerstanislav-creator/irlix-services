<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class Employee extends Model
{
    protected $table = 'employees';

    protected $guarded = [];

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'department_id');
    }

    public function accessRoles(): HasMany
    {
        return $this->hasMany(EmployeeAccessRole::class, 'employee_id');
    }
}
