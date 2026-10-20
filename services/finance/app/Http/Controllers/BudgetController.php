<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class BudgetController extends Controller
{
    private static function permission(Request $r,string $key): void {
        abort_unless(($r->attributes->get('identity')['permissions'][$key]??false)===true,403);
    }
    private static function actor(Request $r): string {
        return (string)($r->attributes->get('identity')['sub']??'unknown');
    }
    private static function audit(Request $r,int $id,string $action): void {
        DB::table('finance_audit_log')->insert([
            'entity_type'=>'budget','entity_id'=>$id,'action'=>$action,
            'actor'=>self::actor($r),'created_at'=>now(),
        ]);
    }
    private static function budget(int $id): object {
        $b=DB::table('budget_versions')->find($id);abort_unless($b,404,'Бюджет не найден');
        return $b;
    }
    private static function validateLines(Request $r): array {
        $validated=$r->validate([
            'lines'=>['required','array','min:1','max:1000'],
            'lines.*.month'=>['required','date_format:Y-m'],
            'lines.*.cost_center'=>['required','string','max:120'],
            'lines.*.article'=>['required','string','max:120'],
            'lines.*.amount'=>['required','numeric','between:-999999999999,999999999999','decimal:0,2'],
        ]);
        $unique=[];
        foreach($validated['lines'] as $line){
            $key=$line['month'].'|'.$line['cost_center'].'|'.$line['article'];
            if(isset($unique[$key]))throw ValidationException::withMessages([
                'lines'=>'В бюджете повторяются месяц, центр затрат и статья'
            ]);
            $unique[$key]=true;
        }
        return $validated['lines'];
    }
    public function index(Request $r) {
        self::permission($r,'finance.view');
        $rows=DB::table('budget_versions')->orderByDesc('id')->limit(100)->get();
        $ids=$rows->pluck('id');
        $lines=DB::table('budget_lines')->whereIn('budget_version_id',$ids)->orderBy('month')->get()
            ->groupBy('budget_version_id');
        return response()->json(['data'=>$rows->map(function($r)use($lines){
            $r->lines=$lines[$r->id]??[];return $r;
        })->values()]);
    }
    public function variance(Request $r,int $id) {
        self::permission($r,'finance.view');
        $month=(string)$r->query('month',now()->format('Y-m'));
        abort_unless(preg_match('/^[0-9]{4}-(0[1-9]|1[0-2])$/',$month),422,'Invalid month');
        $budget=self::budget($id);
        abort_unless($month >= $budget->first_month && $month <= $budget->last_month,422,
            'Месяц вне горизонта бюджета');
        $lines=DB::table('budget_lines')->where('budget_version_id',$id)
            ->where('month',$month)->orderBy('cost_center')->orderBy('article')->get();
        $actual=DB::table('accruals')->where('recognition_month',$month)
            ->select('cost_center','article')->selectRaw('SUM(amount) AS total')
            ->groupBy('cost_center','article')->get()->keyBy(fn($x)=>$x->cost_center.'|'.$x->article);
        $revenue=null;$revenueQuality='not_requested';
        if($lines->contains(fn($line)=>in_array(mb_strtolower(trim((string)$line->article)),['revenue','выручка'],true))) {
            try {
                $source=\App\Support\RevenueReport::month($month);
                $revenueQuality=$source['quality'];
                if($revenueQuality==='complete')$revenue=$source['client_agreed_amount'];
            } catch(\Throwable) {
                $revenueQuality='source_unavailable';
            }
        }
        $rows=$lines->map(function($line)use($actual,$revenue,$revenueQuality){
            $isRevenue=in_array(mb_strtolower(trim((string)$line->article)),['revenue','выручка'],true);
            $source=$isRevenue?null:$actual->get($line->cost_center.'|'.$line->article);
            $value=$isRevenue?$revenue:($source ? (float)$source->total:null);
            $plan=(float)$line->amount;
            return [
                'cost_center'=>$line->cost_center,'article'=>$line->article,
                'plan'=>$plan,'actual'=>$value,
                'deviation'=>$value===null?null:round($value-$plan,2),
                'quality'=>$isRevenue?$revenueQuality:($source?'manual_accruals':'no_actual_entry'),
            ];
        })->values()->all();
        $complete=collect($rows)->every(fn($row)=>$row['actual']!==null);
        return response()->json(['data'=>[
            'budget_id'=>$id,'revision'=>$budget->revision,'month'=>$month,
            'rows'=>$rows,'plan_total'=>round(collect($rows)->sum('plan'),2),
            'actual_total'=>$complete?round(collect($rows)->sum('actual'),2):null,
            'quality'=>$complete?'recorded_actuals':'incomplete',
        ]]);
    }

    public function create(Request $r) {
        self::permission($r,'finance.planning.manage');
        $r->validate([
            'name'=>['required','string','max:160'],
            'scenario'=>['required','in:base,optimistic,pessimistic,custom'],
        ]);
        $lines=self::validateLines($r);
        $months=collect($lines)->pluck('month')->unique()->sort()->values();
        if($months->first()<now()->subYears(8)->format('Y-m'))
            throw ValidationException::withMessages(['lines'=>'План слишком далеко в прошлом']);
        $first=$months->first();
        // A budget always exposes at least a 12-month horizon, even with
        // sparse lines. It may be extended by approved version revisions.
        $minimumEnd=\Carbon\Carbon::parse($first.'-01')->addMonthsNoOverflow(11)->format('Y-m');
        $last=max($months->last(),$minimumEnd);
        abort_if(\Carbon\Carbon::parse($first.'-01')->diffInMonths(\Carbon\Carbon::parse($last.'-01'))>59,
            422,'Период бюджета не может превышать 60 месяцев');
        return DB::transaction(function()use($r,$lines,$first,$last){
            $id=DB::table('budget_versions')->insertGetId([
                'name'=>$r->input('name'),'scenario'=>$r->input('scenario'),
                'plan_key'=>(string)Str::uuid(), 'first_month'=>$first,'last_month'=>$last,
                'status'=>'draft','revision'=>1,'created_by'=>self::actor($r),
                'created_at'=>now(),'updated_at'=>now(),
            ]);
            foreach($lines as $l)DB::table('budget_lines')->insert([
                'budget_version_id'=>$id,'month'=>$l['month'],'cost_center'=>$l['cost_center'],
                'article'=>$l['article'],'amount'=>$l['amount'],'created_at'=>now(),'updated_at'=>now(),
            ]);
            self::audit($r,$id,'created');
            return response()->json(['data'=>self::budget($id)],201);
        });
    }
    public function approve(Request $r,int $id) {
        self::permission($r,'finance.planning.approve');
        return DB::transaction(function()use($r,$id){
            $b=DB::table('budget_versions')->where('id',$id)->lockForUpdate()->first();
            abort_unless($b,404);
            abort_unless($b->status==='draft',409,'Нельзя повторно утвердить бюджет');
            abort_unless(DB::table('budget_lines')->where('budget_version_id',$id)->exists(),422);
            DB::table('budget_versions')->where('id',$id)->update([
                'status'=>'approved','approved_by'=>self::actor($r),
                'approved_at'=>now(),'updated_at'=>now(),
            ]);
            self::audit($r,$id,'approved');
            return response()->json(['data'=>self::budget($id)]);
        });
    }
    public function revise(Request $r,int $id) {
        self::permission($r,'finance.planning.manage');
        $b=self::budget($id);
        abort_unless($b->status==='approved',409,'Новую версию можно создать после утверждения');
        $lines=self::validateLines($r);
        $start=$b->first_month;$end=$b->last_month;
        // A new revision can extend the horizon while preserving the original
        // baseline start and every previously approved revision.
        foreach($lines as $l)abort_unless($l['month']>=$start,422,
            'Новая редакция не может начинаться раньше исходного бюджета');
        $end=max($end,collect($lines)->pluck('month')->max());
        abort_if(\Carbon\Carbon::parse($start.'-01')->diffInMonths(\Carbon\Carbon::parse($end.'-01'))>59,
            422,'Горизонт бюджета не может превышать 60 месяцев');
        return DB::transaction(function()use($r,$b,$lines,$end){
            $latest=DB::table('budget_versions')->where('plan_key',$b->plan_key)->orderByDesc('revision')
                ->lockForUpdate()->first();
            abort_unless($latest && (int)$latest->id===(int)$b->id,409,
                'Для этого бюджета уже существует новая версия');
            $newId=DB::table('budget_versions')->insertGetId([
                'name'=>$b->name,'scenario'=>$b->scenario,'plan_key'=>$b->plan_key,
                'first_month'=>$b->first_month,'last_month'=>$end,
                'revision'=>(int)$b->revision+1,'status'=>'draft',
                'created_by'=>self::actor($r),'created_at'=>now(),'updated_at'=>now(),
            ]);
            foreach($lines as $l)DB::table('budget_lines')->insert([
                'budget_version_id'=>$newId,'month'=>$l['month'],
                'cost_center'=>$l['cost_center'],'article'=>$l['article'],
                'amount'=>$l['amount'],'created_at'=>now(),'updated_at'=>now(),
            ]);
            self::audit($r,$newId,'revision_created');
            return response()->json(['data'=>self::budget($newId)],201);
        });
    }
};
