<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class KeycloakAuthSync extends Model
{
    protected $table = 'keycloak_auth_sync';
    protected $guarded = [];
}
