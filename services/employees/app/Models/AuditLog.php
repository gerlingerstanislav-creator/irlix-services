<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class AuditLog extends Model
{
    protected $table = 'audit_log';
    protected $guarded = [];
}
