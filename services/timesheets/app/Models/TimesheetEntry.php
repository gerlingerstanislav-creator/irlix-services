<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
final class TimesheetEntry extends Model
{
    protected $table = 'timesheet_entries';
    protected $guarded = [];
    protected $casts = ['term_conflict' => 'boolean', 'hours' => 'decimal:2'];
}
