<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class VacationsPermissionAudit extends Model
{
    public $timestamps = false;
    protected $table = 'vacations_permission_audit';
    protected $guarded = [];
}
