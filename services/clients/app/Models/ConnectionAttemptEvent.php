<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class ConnectionAttemptEvent extends Model
{
    protected $table = 'connection_attempt_events';
    protected $guarded = [];
}
