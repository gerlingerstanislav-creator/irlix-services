<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class ClientMember extends Model
{
    protected $table = 'client_members';

    public function terms(): HasMany
    {
        return $this->hasMany(MemberTerm::class, 'client_member_id');
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(ProjectAssignment::class, 'client_member_id');
    }
}
