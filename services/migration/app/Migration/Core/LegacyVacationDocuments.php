<?php
namespace App\Migration\Core;

use DomainException;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use Illuminate\Support\Facades\Http;

/** Bounded read-only downloads; all bytes are staged before target writes. */
class LegacyVacationDocuments
{
    private array $files = [];
    private const LIMIT = 10 * 1024 * 1024;

    public static function filename(string $employee, string $type, string $from, string $to, string $key, string $original): string
    {
        $types = ['paid_vacation'=>'Оплачиваемый отпуск','unpaid_vacation'=>'Неоплачиваемый отпуск','sick_leave'=>'Больничный','maternity_leave'=>'Декрет','day_off'=>'Отгул'];
        $label = in_array($type,['paid_vacation','unpaid_vacation'],true) ? 'Заявление' : 'Документ';
        $ext = strtolower(pathinfo($original, PATHINFO_EXTENSION));
        if (!in_array($ext,['pdf','png','jpg','jpeg','doc','docx'],true)) throw new DomainException('Неподдерживаемый формат исходного документа');
        $name = $label.' — '.$employee.' — '.($types[$type] ?? $type).' — '.$from.'_'.$to;
        $name = preg_replace('/[\x00-\x1f\x7f<>:"\/\\\\|?*]/u',' ', $name);
        $name = trim(preg_replace('/\s+/u',' ', $name), '. ');
        $name = mb_strcut($name, 0, 205, 'UTF-8');
        return $name.' — '.substr(hash('sha256',$key),0,12).'.'.$ext;
    }

    protected function pinnedOptions(string $url): array
    {
        $parts = parse_url($url);
        if (!$parts || !in_array($parts['scheme'] ?? '',['http','https'],true) || empty($parts['host']) || isset($parts['user']) || isset($parts['pass']) || preg_match('/[\x00-\x20\x7f]/',$url)) throw new DomainException('Некорректная HTTP/HTTPS ссылка исходного документа');
        $host = strtolower(trim($parts['host'],'[]'));
        $port = $parts['port'] ?? ($parts['scheme']==='https' ? 443 : 80);
        if (!in_array($port,[80,443],true)) throw new DomainException('Нестандартный порт ссылки документа не разрешён');
        $addresses = filter_var($host,FILTER_VALIDATE_IP) ? [$host] : (gethostbynamel($host) ?: []);
        if (!$addresses) throw new DomainException('Не удалось определить адрес хранилища документа');
        $trusted = array_filter(array_map('strtolower',array_merge(explode(',',(string) getenv('MIGRATION_LEGACY_FILE_HOSTS')),[(string) config('migration.legacy.vacations.database.host')])));
        foreach ($addresses as $ip) {
            if (!filter_var($ip,FILTER_VALIDATE_IP,FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) && !in_array($host,$trusted,true)) throw new DomainException('Ссылка ведёт во внутреннюю сеть. Требуется доверенный хост MIGRATION_LEGACY_FILE_HOSTS');
            // Loopback, link-local/cloud metadata and unspecified addresses are never valid storage targets.
            if (preg_match('/^(127\.|169\.254\.|0\.|::ffff:|fe80:)/i',$ip) || in_array(strtolower($ip),['::','::1'],true)) throw new DomainException('Ссылка документа ведёт на запрещённый служебный адрес');
        }
        return ['allow_redirects'=>false,'curl'=>[CURLOPT_RESOLVE=>[$host.':'.$port.':'.$addresses[0]]]];
    }

    public function stage(string $key, string $url): array
    {
        if (isset($this->files[$key])) return $this->files[$key];
        $path = tempnam(sys_get_temp_dir(),'vacation-doc-');
        if (!$path) throw new DomainException('Не удалось подготовить временный файл документа');
        try {
            for ($redirects=0; $redirects<5; $redirects++) {
                $response = Http::connectTimeout(5)->timeout(30)->withOptions($this->pinnedOptions($url)+[
                    'sink'=>$path,
                    'progress'=>function($total,$downloaded) { if ($total > self::LIMIT || $downloaded > self::LIMIT) throw new DomainException('Документ превышает допустимые 10 МБ'); },
                ])->get($url);
                if (in_array($response->status(),[301,302,303,307,308],true)) {
                    $location = $response->header('Location');
                    if (!$location) throw new DomainException('Хранилище вернуло перенаправление без ссылки');
                    $url = (string) UriResolver::resolve(new Uri($url),new Uri($location));
                    continue;
                }
                if (!$response->successful()) throw new DomainException('Хранилище вернуло HTTP '.$response->status());
                clearstatcache(true,$path);$size = filesize($path);
                if (!$size || $size > self::LIMIT) throw new DomainException('Документ пустой или превышает допустимые 10 МБ');
                $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($path);
                if (in_array($mime,['text/html','application/json','text/plain'],true)) throw new DomainException('По ссылке получена страница или ответ API вместо документа');
                return $this->files[$key]=['path'=>$path,'size'=>$size,'mime'=>$mime];
            }
            throw new DomainException('Слишком много перенаправлений документа');
        } catch (\Throwable $e) {
            @unlink($path);
            if ($e instanceof DomainException) throw $e;
            throw new DomainException('Не удалось скачать исходный документ: проверьте доступность ссылки с сервера и права скачивания');
        }
    }

    public function install(array $file, string $name): string
    {
        $directory = getenv('MIGRATION_VACATIONS_FILES_PATH') ?: '/vacation-files';
        if (!is_dir($directory.'/attachments') && !mkdir($directory.'/attachments',0770,true)) throw new DomainException('Недоступно хранилище документов нового сервиса');
        $target = $directory.'/attachments/'.$name;
        $hash = hash_file('sha256',$file['path']);
        if (is_file($target)) {
            if (hash_file('sha256',$target)!==$hash) throw new DomainException('Файл с этим именем уже существует с другим содержимым');
            return 'attachments/'.$name;
        }
        $tmp = tempnam($directory.'/attachments','.import-');
        if (!$tmp || !copy($file['path'],$tmp)) throw new DomainException('Не удалось сохранить документ');
        chmod($tmp,0640);
        if (!rename($tmp,$target)) { @unlink($tmp);throw new DomainException('Не удалось завершить сохранение документа'); }
        return 'attachments/'.$name;
    }

    public function __destruct() { foreach ($this->files as $file) @unlink($file['path']); }
}
