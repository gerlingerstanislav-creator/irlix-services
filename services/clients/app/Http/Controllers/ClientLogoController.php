<?php

namespace App\Http\Controllers;

use App\Services\ClientLogoService;
use Illuminate\Http\Request;

final class ClientLogoController extends Controller
{
    public static function serialize(object $client): array
    {
        $row = (array) $client;
        $row['logo_url'] = !empty($row['logo_storage_path'])
            ? '/api/clients/clients/'.$client->id.'/logo?v='.rawurlencode(basename($row['logo_storage_path']))
            : null;
        unset($row['logo_storage_path'], $row['logo_mime_type']);
        return $row;
    }

    public function show(int $client)
    {
        $meta = app(ClientLogoService::class)->fileMeta($client);
        return response()->file($meta['path'], [
            'Content-Type' => $meta['mime'],
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, max-age=3600',
        ]);
    }

    public function store(Request $request, int $client)
    {
        $logos = app(ClientLogoService::class);
        abort_unless($logos->exists($client), 404, 'Клиент не найден.');
        $request->validate(['logo' => ['required', 'image', 'mimes:png,jpg,jpeg,webp', 'max:1024']]);
        return response()->json(['data' => self::serialize($logos->replace($client, $request->file('logo')))]);
    }

    public function destroy(int $client)
    {
        app(ClientLogoService::class)->remove($client);
        return response()->noContent();
    }
}
