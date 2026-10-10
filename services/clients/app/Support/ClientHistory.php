<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

final class ClientHistory
{
    public static function record(int $clientId, string $type, string $title, ?string $description = null, ?string $username = null): void
    {
        DB::table('client_history_events')->insert([
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
