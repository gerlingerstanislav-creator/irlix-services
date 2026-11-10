<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class ConnectionAttemptEvent extends Model
{
    protected $table = 'connection_attempt_events';
    protected $guarded = [];

    // Audit/event tables are append-only and have no updated_at column.
    // Eloquent bulk updates during employee purge must not synthesize one.
    public $timestamps = false;
}
