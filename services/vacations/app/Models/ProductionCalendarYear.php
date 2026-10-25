<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class ProductionCalendarYear extends Model
{
    protected $table = 'production_calendar_years';
    protected $primaryKey = 'year';
    public $timestamps = false;
    protected $guarded = [];
}
