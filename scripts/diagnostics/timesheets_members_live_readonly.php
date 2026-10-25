<?php
/**
 * One-off, read-only production Timesheets member reconciliation.
 * Output: opaque legacy/member IDs, target connection IDs, counts, fixed error codes.
 * Never output names, logins, UUIDs, projects, hours, source rows, or credentials.
 * The script has no inserts, updates, deletes or migration commands.
 */
use App\Migration\Core\LegacyReader;
use App\Migration\Core\MigrationStore;
use App\Migration\Core\TimesheetsResolution;
use App\Migration\Core\TimesheetsUnresolvedMembers;
use App\Migration\Core\SourceRowConflict;
use Illuminate\Support\Facades\DB;

require getcwd().'/vendor/autoload.php';
$app = require getcwd().'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

function safeJson(array $data): void
{
    echo json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)."\n";
}
function failureCode(\Throwable $exception): string
{
    if ($exception instanceof SourceRowConflict) return $exception->reason;
    if ($exception instanceof \DomainException) return 'DOMAIN_CONFLICT';
    return 'READ_ONLY_PROBE_ERROR';
}
try {
    $reader = new LegacyReader('timesheets');
    $reader->assertSafe();
    $store = app(MigrationStore::class);
    $source = $reader->select('SELECT * FROM public.members ORDER BY id', [], false);
    $users = $reader->select('SELECT * FROM public.users', [], false);
    $rates = $reader->select('SELECT id, member_id, "from", "to", workload FROM public.rates', [], false);
    $counts = $reader->select('SELECT rate_id, count(*) AS n FROM public.timesheets WHERE rate_id IS NOT NULL GROUP BY rate_id', [], false);

    $usersByUuid = [];
    foreach ($users as $user) {
        $uuid = mb_strtolower(trim((string)($user->employee_id ?? '')));
        if ($uuid !== '') $usersByUuid[$uuid][] = (array)$user;
    }
    $ratesByMember = [];
    $rateToMember = [];
    foreach ($rates as $rate) {
        $id = (string)$rate->id;
        $member = (string)$rate->member_id;
        $ratesByMember[$member][] = ['id'=>$id, 'from'=>$rate->from, 'to'=>$rate->to,
            'workload'=>$rate->workload, 'fact_dates'=>[]];
        $rateToMember[$id] = $member;
    }
    $factsByRate = [];
    foreach ($counts as $row) $factsByRate[(string)$row->rate_id] = (int)$row->n;
    $sourceIds = [];
    foreach ($source as $row) $sourceIds[(string)$row->id] = true;
    $mapped = array_fill_keys(DB::table('migration_mappings')->where('service','timesheets')
        ->where('entity_type','members')->pluck('legacy_id')->map('strval')->all(), true);
    $overrides = DB::table('migration_overrides')->where('service','timesheets')
        ->where('entity_type','members')->pluck('target_id','legacy_id')->all();

    $stats = [
        'source_total'=>count($source), 'mapped_members'=>count($mapped),
        'unmapped'=>0, 'unmapped_with_facts'=>0, 'unmapped_fact_count'=>0,
        'metadata_only_no_rates'=>0, 'override_pending'=>0,
        'staff_unique'=>0, 'partner_unique'=>0,
        'identity_unresolved'=>0, 'rate_unresolved'=>0,
        'orphan_rates_without_member'=>0, 'orphan_rates_with_facts'=>0,
    ];
    $resolved=[]; $blocked=[];
    foreach ($rates as $rate) if (!isset($sourceIds[(string)$rate->member_id])) {
        ++$stats['orphan_rates_without_member'];
        if (($factsByRate[(string)$rate->id] ?? 0) > 0) ++$stats['orphan_rates_with_facts'];
    }

    foreach ($source as $sourceRow) {
        $member = (array)$sourceRow;
        $id = (string)$member['id'];
        if (isset($mapped[$id])) continue;
        ++$stats['unmapped'];
        $memberRates = $ratesByMember[$id] ?? [];
        $facts = 0;
        foreach ($memberRates as $rate) $facts += ($factsByRate[$rate['id']] ?? 0);
        if ($facts > 0) {
            ++$stats['unmapped_with_facts'];
            $stats['unmapped_fact_count'] += $facts;
        }
        if (isset($overrides[$id])) ++$stats['override_pending'];
        if (!$memberRates && !isset($overrides[$id])) {
            ++$stats['metadata_only_no_rates'];
            continue;
        }
        try {
            if (TimesheetsUnresolvedMembers::isExternal((string)($member['memberable_type'] ?? ''))) {
                $target = TimesheetsResolution::partner($member, $store);
                $category = 'partner_unique';
            } elseif (mb_strtolower(trim((string)($member['memberable_type'] ?? ''))) === 'employee') {
                $uuid = mb_strtolower(trim((string)($member['employee_id'] ?? '')));
                $employees=[];
                foreach ($usersByUuid[$uuid] ?? [] as $user) {
                    $employees[TimesheetsResolution::employee($user,$store)] = true;
                }
                if (count($employees) !== 1) {
                    throw new SourceRowConflict('EMPLOYEE_IDENTITY_UNRESOLVED',
                        'Corporate identity has no unique target');
                }
                $employee = (int)array_key_first($employees);
                // Only if there are multiple possible connections do real fact dates
                // help prove one. Never invent a match based on numeric IDs.
                $candidates = TimesheetsResolution::candidates($employee);
                if (count($candidates) > 1 && $memberRates && $facts > 0) {
                    $rateIds = array_column($memberRates,'id');
                    $placeholders = implode(',',array_fill(0,count($rateIds),'?'));
                    $dates = $reader->select('SELECT DISTINCT rate_id, date FROM public.timesheets WHERE rate_id IN ('
                        .$placeholders.') ORDER BY rate_id, date', $rateIds, false);
                    $byRate=[];
                    foreach ($dates as $fact) $byRate[(string)$fact->rate_id][] = (string)$fact->date;
                    foreach ($memberRates as &$rate) $rate['fact_dates'] = $byRate[$rate['id']] ?? [];
                    unset($rate);
                }
                $target = TimesheetsResolution::member($member,$employee,$memberRates,$store);
                $category = 'staff_unique';
            } else {
                throw new SourceRowConflict('MEMBER_TYPE_UNKNOWN','Unrecognized member type');
            }
            $stats[$category]++;
            $entry=['source_member_id'=>$id,'target_client_member_id'=>(int)$target['target_id'],
                'kind'=>$category,'source_rates'=>count($memberRates),'source_facts'=>$facts,
                'has_saved_override'=>isset($overrides[$id])];
            $resolved[]=$entry;

            $rateFailures=[];
            foreach ($memberRates as $rate) {
                try {
                    TimesheetsResolution::rate($rate,$target,$rate['fact_dates'] ?? [],$store);
                } catch (\Throwable $error) {
                    $rateFailures[failureCode($error)] = true;
                }
            }
            if ($rateFailures) {
                ++$stats['rate_unresolved'];
                $blocked[]=['source_member_id'=>$id,'issue'=>'RATE_DEPENDENCY',
                    'codes'=>array_keys($rateFailures),'source_facts'=>$facts];
            }
        } catch (\Throwable $error) {
            ++$stats['identity_unresolved'];
            $blocked[]=['source_member_id'=>$id,'issue'=>'MEMBER_IDENTITY',
                'code'=>failureCode($error),'source_rates'=>count($memberRates),'source_facts'=>$facts,
                'has_saved_override'=>isset($overrides[$id])];
        }
    }
    usort($resolved, static fn($a,$b)=>(int)$a['source_member_id'] <=>(int)$b['source_member_id']);
    usort($blocked, static fn($a,$b)=>(int)$a['source_member_id'] <=>(int)$b['source_member_id']);
    safeJson(['kind'=>'summary','result'=>$stats,
        'resolved_pairs_count'=>count($resolved),'blocked_cases_count'=>count($blocked),
        'production_dry_run_not_triggered'=>true]);
    // Source/target numeric database IDs only; no identity, financial or project data.
    foreach (array_slice($resolved,0,40) as $pair) safeJson(['kind'=>'proven_connection']+$pair);
    if (count($resolved)>40) safeJson(['kind'=>'other_proven_connections','count'=>count($resolved)-40]);
    foreach (array_slice($blocked,0,40) as $case) safeJson(['kind'=>'needs_review']+$case);
    if (count($blocked)>40) safeJson(['kind'=>'other_needs_review','count'=>count($blocked)-40]);
} catch (\Throwable $error) {
    // Do not disclose exception messages; they may contain personal data or secrets.
    safeJson(['kind'=>'probe_failed','exception_class'=>get_class($error)]);
    exit(2);
}
