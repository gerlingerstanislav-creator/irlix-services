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
        return $query
            ->where($alias.'.account_type', self::TYPE_EMPLOYEE)
            ->where($alias.'.login', '<>', 'admin')
            ->where(static function (Builder $query) use ($alias): void {
                $query->whereNull($alias.'.cooperation_type')
                    ->orWhere($alias.'.cooperation_type', '<>', 'Системный аккаунт');
            });
    }

    public function scopeSystem(Builder $query): Builder
    {
        // Include records with stale account_type values until reconciliation.
        // A bootstrap/legacy system account must never silently disappear.
        return $query->where(static function (Builder $query): void {
            $query->where('employees.account_type', self::TYPE_SYSTEM)
                ->orWhere('employees.login', 'admin')
                ->orWhere('employees.cooperation_type', 'Системный аккаунт');
        });
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
