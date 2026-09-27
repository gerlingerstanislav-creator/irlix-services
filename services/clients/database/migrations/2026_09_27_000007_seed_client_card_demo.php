<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        $client = DB::table('clients')->where('name', 'Демо Медиа Лаб')->first();
        if (!$client) {
            return;
        }

        DB::table('clients')->where('id', $client->id)->update([
            'description' => 'Демо-клиент для проверки карточки, коммерческих параметров и связанных сущностей.',
            'act_approval_days' => 15,
            'payment_days' => 30,
            'technologies' => json_encode(['PHP', 'Vue', 'QA'], JSON_UNESCAPED_UNICODE),
            'updated_at' => now(),
        ]);

        if (!DB::table('client_legal_entities')->where('client_id', $client->id)->exists()) {
            DB::table('client_legal_entities')->insert([
                [
                    'client_id' => $client->id,
                    'name' => 'ООО «Демо Медиа Лаб»',
                    'inn' => '0000000000',
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
                [
                    'client_id' => $client->id,
                    'name' => 'ООО «Демо Диджитал»',
                    'inn' => '0000000001',
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
            ]);
        }

        if (!DB::table('client_notes')->where('client_id', $client->id)->exists()) {
            DB::table('client_notes')->insert([
                [
                    'client_id' => $client->id,
                    'text' => 'Демо-заметка: согласовать следующий отчётный период с клиентом.',
                    'created_by_username' => 'demo.account',
                    'created_at' => now()->subDays(2),
                    'updated_at' => now()->subDays(2),
                ],
                [
                    'client_id' => $client->id,
                    'text' => 'Демо-заметка: уточнить потребность по дополнительному специалисту.',
                    'created_by_username' => 'demo.account',
                    'created_at' => now()->subDay(),
                    'updated_at' => now()->subDay(),
                ],
            ]);
        }
    }

    public function down(): void
    {
        $client = DB::table('clients')->where('name', 'Демо Медиа Лаб')->first();
        if (!$client) {
            return;
        }
        DB::table('client_notes')->where('client_id', $client->id)->where('created_by_username', 'demo.account')->delete();
        DB::table('client_legal_entities')->where('client_id', $client->id)->whereIn('inn', ['0000000000', '0000000001'])->delete();
    }
};
