<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class ConnectionAttempt extends Model
{
    protected $table = 'connection_attempts';
    protected $guarded = [];
}
