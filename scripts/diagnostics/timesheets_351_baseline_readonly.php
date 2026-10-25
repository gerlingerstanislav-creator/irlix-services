<?php
/** Strictly read-only comparison of Timesheets run 351 predecessor dry-run batch hash. */
use App\Migration\Core\LegacyReader;
use Illuminate\Support\Facades\DB;
require getcwd().'/vendor/autoload.php';
$app=require getcwd().'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
function safe(array $row): void {echo json_encode($row,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES)."\n";}
try {
    $source=new LegacyReader('timesheets');
    $source->assertSafe();
    $dry=DB::table('migration_runs')->where('service','timesheets')->where('mode','dry-run')
        ->where('id','<',351)->orderByDesc('id')->first(['id','status','summary']);
    if(!$dry){safe(['check'=>'dry_run','found'=>false]);exit(2);}
    $control=function (int $runId): array {
        return DB::table('migration_run_events')->where('migration_run_id',$runId)->where('event','console')
            ->get(['context'])->map(fn($r)=>json_decode($r->context??'{}',true))->all();
    };
    $run350=(int)$dry->id;
    $baseline=DB::table('migration_batch_progress')->where('migration_run_id',$run350)
        ->where('table_name','timesheets')->orderBy('batch_number')
        ->get(['batch_number','first_source_id','last_source_id','row_count','source_sha256']);
    safe(['check'=>'dry_run','run_id'=>$run350,'status'=>$dry->status,'batch_count'=>$baseline->count(),
        'first_batch_size'=>$baseline->first()?->row_count,'control_ownership_matches'=>$control(351)===$control($run350)]);
    if(!$baseline->count()){safe(['check'=>'missing_baseline']);exit(0);}
    $n=0;$mismatch=0;$size=0;$firstDivergent=null;$max=0;
    foreach($baseline as $batch){
        $rows=$source->select('SELECT * FROM public.timesheets WHERE id >= ? AND id <= ? ORDER BY id',
            [$batch->first_source_id,$batch->last_source_id],false);
        $data=array_map(static fn($r)=>(array)$r,$rows);
        $sha=hash('sha256',json_encode($data,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
        ++$n;
        if((int)$batch->row_count !== count($data))++$size;
        if(!hash_equals((string)$batch->source_sha256,$sha)){
            ++$mismatch;
            $firstDivergent??=(int)$batch->batch_number;
        }
        $max=$batch->last_source_id;
        if($n>=3)break; // Only inspect earliest source batches relevant to 0/64003 failure
    }
    $bounds=$source->select('SELECT count(*) AS n, max(id) AS max_id FROM public.timesheets',[],false);
    safe(['check'=>'baseline_first_batches','checked'=>$n,'mismatch'=>$mismatch,
        'row_count_changes'=>$size,'first_mismatching_batch'=>$firstDivergent,
        'source_row_count'=>(int)($bounds[0]->n??0),'dryrun_last_batch_end_id_present'=>($max!==null)]);
} catch(\Throwable $e){
    safe(['check'=>'probe_error','class'=>get_class($e)]);exit(2);
}
