<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class Employee extends Model
{
    public const TYPE_EMPLOYEE = 'employee';
    public const TYPE_SYSTEM = 'system';

    protected $table = 'employees';

    protected $guarded = [];

    /** Explicitly limit HR queries; identity resolution must still see both types. */
    public function scopeBusiness(Builder $query, string $alias = 'employees'): Builder
    {
        return $query->where($alias.'.account_type', self::TYPE_EMPLOYEE);
    }

    public function scopeSystem(Builder $query): Builder
    {
        return $query->where('employees.account_type', self::TYPE_SYSTEM);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'department_id');
    }

    public function accessRoles(): HasMany
    {
        return $this->hasMany(EmployeeAccessRole::class, 'employee_id');
    }
}
