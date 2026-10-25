<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class MemberTerm extends Model
{
    protected $table = 'member_terms';

    public function clientMember(): BelongsTo
    {
        return $this->belongsTo(ClientMember::class, 'client_member_id');
    }
}
