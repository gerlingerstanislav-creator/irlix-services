<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class AdditionalTechnology extends Model
{
    protected $table = 'additional_technologies';
    protected $guarded = [];
    protected $casts = ['active' => 'boolean'];
}
