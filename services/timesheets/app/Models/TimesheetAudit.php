<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class TimesheetAudit extends Model
{
    protected $table = 'timesheet_audit';
    protected $guarded = [];
    public $timestamps = false;
}
