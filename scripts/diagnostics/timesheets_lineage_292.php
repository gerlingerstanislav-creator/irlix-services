<?php
/**
 * Safe read-only experiment: legacy FK lineage and target Clients coverage.
 * Output only schema metadata and aggregated counts, no names or source rows.
 */
declare(strict_types=1);

use App\Migration\Core\LegacyReader;
use Illuminate\Support\Facades\DB;

require getcwd().'/vendor/autoload.php';
$app = require getcwd().'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

function out(string $name, array $data): void {
    echo json_encode(['check'=>$name]+$data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n";
}

foreach (['timesheets','clients'] as $service) {
    try {
        $legacy = new LegacyReader($service);
        $legacy->assertSafe();
        $tables = ['members','rates','timesheets'];
        if ($service === 'clients') $tables = ['members','projects','clients','subcontracts'];
        foreach ($tables as $table) {
            $q = $legacy->select("SELECT count(*) AS n FROM information_schema.tables WHERE table_schema='public' AND table_name=?", [$table], false);
            if (empty($q) || (int)$q[0]->n === 0) continue;
            $columns = $legacy->select("SELECT column_name FROM information_schema.columns WHERE table_schema='public' AND table_name=? ORDER BY ordinal_position", [$table], false);
            out('legacy_schema', ['service'=>$service,'table'=>$table,'columns'=>array_map(fn($v)=>(string)$v->column_name,$columns)]);
        }
        $fk = $legacy->select("SELECT tc.table_name,kcu.column_name,ccu.table_name AS target_table,ccu.column_name AS target_column FROM information_schema.table_constraints tc JOIN information_schema.key_column_usage kcu ON tc.constraint_name=kcu.constraint_name AND tc.table_schema=kcu.table_schema JOIN information_schema.constraint_column_usage ccu ON tc.constraint_name=ccu.constraint_name AND tc.table_schema=ccu.table_schema WHERE tc.table_schema='public' AND tc.constraint_type='FOREIGN KEY' ORDER BY tc.table_name,kcu.column_name", [], false);
        foreach ($fk as $row) out('legacy_fk',['service'=>$service,'table'=>(string)$row->table_name,'column'=>(string)$row->column_name,'target_table'=>(string)$row->target_table,'target_column'=>(string)$row->target_column]);
    } catch (Throwable $e) { out('legacy_unavailable',['service'=>$service,'error_type'=>get_class($e)]); }
}
foreach (['client_members','member_terms','partner_specialists','projects'] as $table) {
    try {
        $conn=DB::connection('target_clients');
        $count=$conn->table($table)->count();
        out('target_count',['table'=>$table,'rows'=>$count]);
    } catch (Throwable $e) { out('target_unavailable',['table'=>$table,'error_type'=>get_class($e)]); }
}

try {
    $ts = new LegacyReader('timesheets');
    $cl = new LegacyReader('clients');
    $ts->assertSafe();
    $cl->assertSafe();
    $tMembers = $ts->select("SELECT id, employee_id, name, project, memberable_type FROM public.members", [], false);
    $cSubcontracts = $cl->select("SELECT id,employee_id,surname,name,patronymic FROM public.subcontracts", [], false);
    $cMembers = $cl->select("SELECT id,memberable_id,memberable_type,client_id,project_id FROM public.members", [], false);
    $byUuid = [];
    foreach ($cSubcontracts as $sub) {
        $uuid = mb_strtolower(trim((string)($sub->employee_id ?? '')));
        if ($uuid !== '') $byUuid[$uuid][] = (string)$sub->id;
    }
    $attached = [];
    foreach ($cMembers as $cm) {
        $type=mb_strtolower((string)$cm->memberable_type);
        if (!str_contains($type,'subcontract') && !str_contains($type,'partner')) continue;
        $attached[(string)$cm->memberable_id][] = [(string)$cm->client_id,(string)$cm->project_id];
    }
    $stats = ['external_total'=>0,'with_uuid'=>0,'uuid_unique_subcontract'=>0,
        'uuid_ambiguous'=>0,'uuid_missing'=>0,'uuid_one_with_client_attachment'=>0,
        'uuid_one_without_client_attachment'=>0,'no_uuid'=>0];
    foreach ($tMembers as $m) {
        $type=mb_strtolower((string)$m->memberable_type);
        if (!in_array($type,['subcontract','contractor','external','partner'],true)) continue;
        $stats['external_total']++;
        $uuid=mb_strtolower(trim((string)($m->employee_id ?? '')));
        if ($uuid==='') { $stats['no_uuid']++; continue; }
        $stats['with_uuid']++;
        $matches=array_unique($byUuid[$uuid]??[]);
        if (count($matches)>1) { $stats['uuid_ambiguous']++; continue; }
        if (!$matches) { $stats['uuid_missing']++; continue; }
        $stats['uuid_unique_subcontract']++;
        $sid=reset($matches);
        if (!empty($attached[$sid])) $stats['uuid_one_with_client_attachment']++;
        else $stats['uuid_one_without_client_attachment']++;
    }
    out('cross_legacy_partner_identity', $stats);
} catch (Throwable $e) {
    out('cross_legacy_unavailable',['error_type'=>get_class($e)]);
}
