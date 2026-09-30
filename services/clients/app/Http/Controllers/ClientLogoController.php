<?php
namespace App\Http\Controllers;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
final class ClientLogoController extends Controller
{
    public static function serialize(object $client): array
    {
        $row = (array) $client;
        $row['logo_url'] = !empty($row['logo_storage_path']) ? '/api/clients/clients/'.$client->id.'/logo?v='.rawurlencode(basename($row['logo_storage_path'])) : null;
        unset($row['logo_storage_path'], $row['logo_mime_type']);
        return $row;
    }
    public function show(int $client)
    {
        $row = DB::table('clients')->find($client);
        abort_unless($row && $row->logo_storage_path, 404, 'Логотип не найден.');
        $path = storage_path('app/clients/logos/'.basename($row->logo_storage_path));
        abort_unless(is_file($path), 404, 'Логотип не найден.');
        return response()->file($path, ['Content-Type' => $row->logo_mime_type, 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, max-age=3600']);
    }
    public function store(Request $request, int $client)
    {
        abort_unless(DB::table('clients')->where('id', $client)->exists(), 404, 'Клиент не найден.');
        $request->validate(['logo' => ['required', 'image', 'mimes:png,jpg,jpeg,webp', 'max:1024']]);
        $file = $request->file('logo');
        $directory = storage_path('app/clients/logos');
        abort_if(!is_dir($directory) && !mkdir($directory, 0770, true) && !is_dir($directory), 503, 'Хранилище логотипов недоступно.');
        $mime = $file->getMimeType();
        $name = Str::uuid()->toString().'.'.$file->guessExtension();
        $file->move($directory, $name);
        try {
            $old = DB::transaction(function () use ($client, $name, $mime) {
                $row = DB::table('clients')->where('id', $client)->lockForUpdate()->first();
                abort_unless($row, 404, 'Клиент не найден.');
                DB::table('clients')->where('id', $client)->update(['logo_storage_path' => $name, 'logo_mime_type' => $mime, 'updated_at' => now()]);
                return $row->logo_storage_path;
            });
        } catch (\Throwable $e) { @unlink($directory.'/'.$name); throw $e; }
        if ($old) @unlink($directory.'/'.basename($old));
        return response()->json(['data' => self::serialize(DB::table('clients')->find($client))]);
    }
    public function destroy(int $client)
    {
        $old = DB::transaction(function () use ($client) {
            $row = DB::table('clients')->where('id', $client)->lockForUpdate()->first();
            abort_unless($row, 404, 'Клиент не найден.');
            DB::table('clients')->where('id', $client)->update(['logo_storage_path' => null, 'logo_mime_type' => null, 'updated_at' => now()]);
            return $row->logo_storage_path;
        });
        if ($old) @unlink(storage_path('app/clients/logos/'.basename($old)));
        return response()->noContent();
    }
}
