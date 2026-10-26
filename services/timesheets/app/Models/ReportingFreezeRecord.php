<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Local, durable freeze, independent of Clients' schema. */
final class ReportingFreezeRecord extends Model
{
    protected $table = 'reporting_freezes';
    protected $primaryKey = 'reporting_period_id';
    public $incrementing = false;
    protected $guarded = [];
}
