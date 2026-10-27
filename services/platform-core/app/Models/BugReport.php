<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class BugReport extends Model
{
    public $timestamps = false;
    protected $table = 'bug_reports';
    protected $fillable = ['employee_id', 'employee_name', 'reporter_keycloak_sub', 'service_key', 'page_path', 'description', 'status', 'closed_at', 'closed_by_employee_id'];
    protected $casts = ['employee_id' => 'integer', 'closed_by_employee_id' => 'integer', 'created_at' => 'immutable_datetime', 'closed_at' => 'immutable_datetime'];
}
