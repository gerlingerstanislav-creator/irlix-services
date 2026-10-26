<?php

namespace App\Support;

use App\Models\ClientHistoryEvent;

final class ClientHistory
{
    public static function record(int $clientId, string $type, string $title, ?string $description = null, ?string $username = null): void
    {
        ClientHistoryEvent::query()->insert([
            'client_id'=>$clientId,
            'type'=>$type,
            'title'=>$title,
            'text'=>$description,
            'created_by_username'=>$username,
            'created_at'=>now(),
            'updated_at'=>now(),
        ]);
    }
}
