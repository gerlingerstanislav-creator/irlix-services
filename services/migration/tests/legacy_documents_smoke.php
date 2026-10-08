<?php
// No real URLs, files, identities or network calls.
putenv('MIGRATION_METADATA_DATABASE=:memory:');
require __DIR__.'/../vendor/autoload.php';
$app=require __DIR__.'/../bootstrap/app.php';$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use App\Migration\Core\LegacyVacationDocuments;
use Illuminate\Support\Facades\Http;
function checkDocument(bool $ok,string $message): void {if(!$ok)throw new RuntimeException($message);}
class SyntheticDocumentTransport extends LegacyVacationDocuments {
    protected function pinnedOptions(string $url): array { return ['allow_redirects'=>false]; }
}
try {
$documents=new SyntheticDocumentTransport();
$mode='pdf';
Http::fake(function() use (&$mode) { return match($mode) {'pdf'=>Http::response('%PDF-1.4 synthetic document',200,['Content-Type'=>'application/pdf']),'html'=>Http::response('<html>Login</html>',200),default=>Http::response('',404)}; });
$file=$documents->stage('synthetic-key','https://files.example.invalid/a.pdf');
checkDocument(is_file($file['path']) && $file['size']>0 && $file['mime']==='application/pdf','Download bytes and MIME verified');
$documents->stage('synthetic-key','https://files.example.invalid/a.pdf');
checkDocument(Http::recorded()->count()===1,'Staged transfer cached within run');
$mode='html';
try {$documents->stage('html','https://files.example.invalid/login');throw new RuntimeException('Login page accepted');}catch(DomainException $e){}
$mode='404';
try {$documents->stage('missing','https://files.example.invalid/missing');throw new RuntimeException('Missing document accepted');}catch(DomainException $e){checkDocument(str_contains($e->getMessage(),'404'),'HTTP failure classified');}
$name=LegacyVacationDocuments::filename('Synthetic Person','paid_vacation','2026-01-10','2026-01-12','synthetic-key','file.pdf');
checkDocument(str_starts_with($name,'Заявление — ') && strlen($name)<=255,'Readable bounded filename');
$real=new LegacyVacationDocuments();$method=new ReflectionMethod($real,'pinnedOptions');
foreach(['file:///etc/passwd','http://127.0.0.1/a.pdf','http://169.254.169.254/a.pdf','https://user:secret@files.example.invalid/a.pdf','http://10.0.0.1/a.pdf'] as $url) {
 try {$method->invoke($real,$url);throw new RuntimeException('Unsafe URL accepted');}catch(DomainException $e){}
}
echo "Legacy document transfer and URL policy regression passed\n";

} catch (Throwable $e) { fwrite(STDERR,$e->getMessage()."\n");exit(1); }
