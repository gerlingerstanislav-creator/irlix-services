<?php

namespace App\Services;

use App\Models\Client;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class ClientLogoService
{
    public function exists(int $clientId): bool
    {
        return Client::query()->whereKey($clientId)->exists();
    }

    public function current(int $clientId): ?object
    {
        $row = Client::query()->whereKey($clientId)->first();
        return $row ? (object) $row->getRawOriginal() : null;
    }

    public function fileMeta(int $clientId): array
    {
        $row = $this->current($clientId);
        abort_unless($row && $row->logo_storage_path, 404, 'Логотип не найден.');
        $path = storage_path('app/clients/logos/'.basename($row->logo_storage_path));
        abort_unless(is_file($path), 404, 'Логотип не найден.');
        return ['path' => $path, 'mime' => $row->logo_mime_type];
    }

    public function replace(int $clientId, UploadedFile $file): object
    {
        $directory = storage_path('app/clients/logos');
        abort_if(!is_dir($directory) && !mkdir($directory, 0770, true) && !is_dir($directory),
            503, 'Хранилище логотипов недоступно.');
        $mime = $file->getMimeType();
        $name = Str::uuid()->toString().'.'.$file->guessExtension();
        $file->move($directory, $name);
        try {
            $old = DB::transaction(static function () use ($clientId, $name, $mime) {
                $row = Client::query()->whereKey($clientId)->lockForUpdate()->first();
                abort_unless($row, 404, 'Клиент не найден.');
                Client::query()->whereKey($clientId)->update([
                    'logo_storage_path' => $name, 'logo_mime_type' => $mime, 'updated_at' => now(),
                ]);
                return $row->logo_storage_path;
            });
        } catch (\Throwable $exception) {
            @unlink($directory.'/'.$name);
            throw $exception;
        }
        if ($old) @unlink($directory.'/'.basename($old));
        return $this->current($clientId);
    }

    public function remove(int $clientId): void
    {
        $old = DB::transaction(static function () use ($clientId) {
            $row = Client::query()->whereKey($clientId)->lockForUpdate()->first();
            abort_unless($row, 404, 'Клиент не найден.');
            Client::query()->whereKey($clientId)->update([
                'logo_storage_path' => null, 'logo_mime_type' => null, 'updated_at' => now(),
            ]);
            return $row->logo_storage_path;
        });
        if ($old) @unlink(storage_path('app/clients/logos/'.basename($old)));
    }
}
