<?php
namespace App\Support;
use Illuminate\Support\Facades\Http;
use RuntimeException;
final class ProductionCalendar
{
    public function year(int $year): array
    {
        if ($year < 2000 || $year > 2100) throw new RuntimeException('Invalid calendar year');
        $url=rtrim((string)env('PLATFORM_CORE_URL','http://platform-core:8000/api'),'/');
        try {
            $response=Http::timeout(10)->get($url.'/production-calendar',['year'=>$year]);
            if (!$response->successful()) throw new RuntimeException('Platform calendar unavailable');
            $data=$response->json('data');
            if (!is_array($data)) throw new RuntimeException('Invalid calendar response');
            return $data;
        } catch (\Throwable $error) {
            throw new RuntimeException('Platform production calendar unavailable',0,$error);
        }
    }
}
