<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Durable optimistic-version queue for Keycloak corporate profile updates. */
final class KeycloakProfileSync extends Model
{
    protected $table = 'keycloak_profile_sync';
    protected $primaryKey = 'employee_id';
    public $incrementing = false;
    protected $guarded = [];
}
