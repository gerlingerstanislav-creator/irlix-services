<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class ProductionCalendarDay extends Model
{
    protected $table = 'production_calendar_days';
    protected $primaryKey = 'date';
    protected $keyType = 'string';
    public $incrementing = false;
    public $timestamps = false;
    protected $guarded = [];
}
