<?php
// Synthetic secrets only. Exercise the same encrypted profile used by API and worker.
$path = '/tmp/migration-key-'.getmypid();
touch($path.'.sqlite');
putenv('MIGRATION_METADATA_DATABASE='.$path.'.sqlite');
putenv('MIGRATION_CREDENTIAL_KEY_FILE='.$path.'.key');
putenv('MIGRATION_APP_KEY=base64:'.base64_encode(str_repeat('k',32)));
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
\Illuminate\Support\Facades\Artisan::call('migrate', ['--force'=>true]);
function ensureKey(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
try {
    $profiles=app(\App\Migration\Core\ConnectionProfileStore::class);
    $key='base64:'.base64_encode(str_repeat('a',32));
    $input=['host'=>'synthetic.invalid','database'=>'synthetic','username'=>'synthetic_ro','password'=>'synthetic-password','readonly_acknowledged'=>true,'legacy_app_key'=>$key];
    $public=$profiles->save('clients',$input);
    $stored=\Illuminate\Support\Facades\DB::table('migration_connections')->where('service','clients')->first();
    ensureKey($stored->legacy_app_key_encrypted !== $key && !str_contains(json_encode($public),$key),'Public profile never exposes key');
    ensureKey($public['legacy_app_key_configured'],'Presence is public');
    $profiles->save('clients',array_replace($input,['password'=>'','legacy_app_key'=>'']));
    $profiles->apply('clients');
    ensureKey(config('migration.clients_app_key')===$key && !config('migration.clients_defer_rate_amounts'),'Blank preserves key and worker enables rate import');
    foreach (['AES-256-CBC','AES-128-CBC','AES-256-GCM','AES-128-GCM'] as $algorithm) {
        $testKey='base64:'.base64_encode(str_repeat('a',str_contains($algorithm,'128')?16:32));
        $cipher=\App\Migration\Core\LegacyClientsCipher::encrypter($testKey,$algorithm);
        config(['migration.clients_app_key'=>$testKey,'migration.clients_cipher'=>$algorithm]);
        foreach ([$cipher->encryptString('1250.50'), $cipher->encrypt('1250.50'), $cipher->encrypt(1250.5), $cipher->encrypt(1250)] as $payload) {
            ensureKey((float)\App\Migration\Core\LegacyClientsCipher::decryptAmount($payload,'rates.rate')>=1250,'String and serialized numeric formats decrypt');
        }
        try { \App\Migration\Core\LegacyClientsCipher::decryptAmount($cipher->encrypt((object)['rate'=>1250]),'rates.rate'); throw new LogicException('Object accepted'); }
        catch (\App\Migration\Core\SourceRowConflict $e) { ensureKey($e->reason==='RATE_DECRYPTION_FAILED','Objects are not deserialized'); }
        config(['migration.clients_app_key'=>'base64:'.base64_encode(str_repeat('b',str_contains($algorithm,'128')?16:32))]);
        try { \App\Migration\Core\LegacyClientsCipher::decryptAmount($payload,'rates.rate'); throw new LogicException('Wrong key accepted'); }
        catch (\App\Migration\Core\SourceRowConflict $e) { ensureKey(!str_contains($e->getMessage(),$payload),'Failure hides ciphertext'); }
    }
    try { $profiles->save('clients',array_replace($input,['legacy_app_key'=>'invalid'])); throw new LogicException('Invalid key accepted'); }
    catch (InvalidArgumentException $e) {}
    ensureKey(\Illuminate\Support\Facades\DB::table('migration_connections')->where('service','clients')->value('legacy_app_key_encrypted')===$stored->legacy_app_key_encrypted,'Invalid save does not replace secret');
    $profiles->save('clients',array_replace($input,['legacy_app_key'=>'','clear_legacy_app_key'=>true]));
    $profiles->apply('clients');
    ensureKey(config('migration.clients_app_key')===null && config('migration.clients_defer_rate_amounts'),'Removing key clears worker config');
    fwrite(STDOUT,"Legacy key profile/encryption smoke OK\n");
} finally { @unlink($path.'.sqlite'); @unlink($path.'.key'); }
