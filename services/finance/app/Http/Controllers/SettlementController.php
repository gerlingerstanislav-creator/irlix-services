<?php

namespace App\Http\Controllers;

use App\Support\ClientsGateway;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class SettlementController extends Controller
{
    private static function authorize(Request $request, string $permission): void
    {
        abort_unless(($request->attributes->get('identity')['permissions'][$permission] ?? false) === true, 403);
    }

    private static function actor(Request $request): string
    {
        return (string) ($request->attributes->get('identity')['sub'] ?? 'unknown');
    }

    private static function audit(Request $request, string $kind, int $id, string $action, array $metadata=[]): void
    {
        DB::table('finance_audit_log')->insert([
            'entity_type'=>$kind, 'entity_id'=>$id, 'action'=>$action,
            'actor'=>self::actor($request),
            'details'=>json_encode($metadata, JSON_UNESCAPED_UNICODE),
            'created_at'=>now(),
        ]);
    }

    private static function cents($amount): int
    {
        return (int) round((float) $amount * 100);
    }

    private static function lockPeriod(int $period): object
    {
        DB::table('finance_period_settlements')->insertOrIgnore([
            'reporting_period_id'=>$period, 'status'=>'open',
            'ledger_version'=>1, 'created_at'=>now(), 'updated_at'=>now(),
        ]);
        $row = DB::table('finance_period_settlements')
            ->where('reporting_period_id',$period)->lockForUpdate()->first();
        abort_if(!$row || $row->status!=='open', 423, 'Финансовый период уже завершён или синхронизируется');
        return $row;
    }

    public function issue(Request $r, int $invoice)
    {
        self::authorize($r,'finance.invoices.manage');
        $existing=DB::table('invoices')->find($invoice);
        abort_unless($existing,404,'Счёт не найден');
        ClientsGateway::period((int)$existing->reporting_period_id,(int)$existing->client_id,true);
        return DB::transaction(function()use($r,$invoice){
            $i=DB::table('invoices')->where('id',$invoice)->lockForUpdate()->first();
            $period=self::lockPeriod((int)$i->reporting_period_id);
            abort_unless($i->document_status==='draft',409,'Выставить можно только черновик');
            abort_unless($i->number && $i->issued_on,422,'Введите номер и дату документа');
            DB::table('invoices')->where('id',$invoice)->update([
                'document_status'=>'issued', 'version'=>$i->version+1, 'updated_at'=>now(),
            ]);
            DB::table('finance_period_settlements')->where('id',$period->id)->increment('ledger_version');
            self::audit($r,'invoice',$invoice,'issued');
            return response()->json(['data'=>DB::table('invoices')->find($invoice)]);
        });
    }

    public function cancel(Request $r,int $invoice)
    {
        self::authorize($r,'finance.invoices.manage');
        $existing=DB::table('invoices')->find($invoice);
        abort_unless($existing,404);
        ClientsGateway::period((int)$existing->reporting_period_id,(int)$existing->client_id);
        return DB::transaction(function()use($r,$invoice){
            $i=DB::table('invoices')->where('id',$invoice)->lockForUpdate()->first();
            $p=self::lockPeriod((int)$i->reporting_period_id);
            abort_if($i->document_status==='cancelled',409,'Уже отменён');
            abort_if(DB::table('payment_allocations')->where('invoice_id',$invoice)->whereNull('reversed_at')->exists(),409,
                'Нельзя отменить счёт с распределённой оплатой');
            DB::table('invoices')->where('id',$invoice)->update([
                'document_status'=>'cancelled','version'=>$i->version+1,'updated_at'=>now()
            ]);
            DB::table('finance_period_settlements')->where('id',$p->id)->increment('ledger_version');
            self::audit($r,'invoice',$invoice,'cancelled');
            return response()->json(['data'=>DB::table('invoices')->find($invoice)]);
        });
    }

    private static function settlementIfPaid(int $period, Request $r): ?array
    {
        // Called only with the settlement row locked in a transaction.
        $issued=DB::table('invoices')->where('reporting_period_id',$period)
            ->where('document_status','issued')->orderBy('id')->lockForUpdate()->get();
        if ($issued->isEmpty()) return null;
        $total=0;
        foreach ($issued as $i) {
            $paid=DB::table('payment_allocations as a')
                ->join('cash_transactions as t','t.id','=','a.cash_transaction_id')
                ->where('a.invoice_id',$i->id)->whereNull('a.reversed_at')->where('t.status','confirmed')->sum('a.amount');
            $cents=self::cents($i->amount);
            if ($cents<1 || self::cents($paid)!==$cents) return null;
            $total+=$cents;
        }
        $row=DB::table('finance_period_settlements')->where('reporting_period_id',$period)->first();
        $version=(int)$row->ledger_version + 1;
        $evidence=(string)Str::uuid();
        DB::table('finance_period_settlements')->where('id',$row->id)->update([
            'status'=>'pending','ledger_version'=>$version,'evidence_id'=>$evidence,'updated_at'=>now(),
        ]);
        self::audit($r,'period',$period,'payment_ready',['version'=>$version,'issued'=>$issued->count()]);
        return [
            'period'=>$period,'ledger_version'=>$version,'invoice_count'=>$issued->count(),
            'paid_total_kopecks'=>$total,'evidence_id'=>$evidence,
        ];
    }

    private static function publishPaid(Request $r, array $claim): bool
    {
        // The remote RPC is intentionally OUTSIDE the local transaction.
        // On outage the immutable pending claim is retried using its evidence ID.
        try {
            ClientsGateway::confirmPaid((int)$claim['period'], [
                'ledger_version'=>$claim['ledger_version'],
                'invoice_count'=>$claim['invoice_count'],
                'paid_total_kopecks'=>$claim['paid_total_kopecks'],
                'evidence_id'=>$claim['evidence_id'],
            ]);
        } catch (\Throwable $e) {
            return false;
        }
        DB::transaction(function()use($r,$claim){
            $row=DB::table('finance_period_settlements')
                ->where('reporting_period_id',$claim['period'])->lockForUpdate()->first();
            if ($row && $row->status==='pending' && (int)$row->ledger_version===$claim['ledger_version']) {
                DB::table('finance_period_settlements')->where('id',$row->id)->update([
                    'status'=>'synced','synced_at'=>now(),'updated_at'=>now()
                ]);
                self::audit($r,'period',(int)$claim['period'],'payment_synced');
            }
        });
        return true;
    }

    public function allocate(Request $r,int $receipt)
    {
        self::authorize($r,'finance.invoices.manage');
        $input=$r->validate([
            'allocations'=>['required','array','min:1','max:100'],
            'allocations.*.invoice_id'=>['required','integer','min:1','distinct'],
            'allocations.*.amount'=>['required','numeric','gt:0','decimal:0,2'],
        ]);
        $items=collect($input['allocations'])->map(fn($x)=>[
            'id'=>(int)$x['invoice_id'],'cents'=>self::cents($x['amount'])
        ])->all();
        abort_if(collect($items)->contains(fn($x)=>$x['cents']<1),422,'Сумма должна быть положительной');
        $ids=array_column($items,'id');
        // Reject a locally known internal transfer before calling Clients.
        // The same invariant is re-checked under cash row lock below, so this
        // fast fail does not weaken concurrency or financial authorization.
        $receiptCheck=DB::table('cash_transactions')->where('id',$receipt)->first();
        abort_unless($receiptCheck,404,'Поступление не найдено');
        abort_unless($receiptCheck->status==='confirmed'
            && $receiptCheck->direction==='in'
            && $receiptCheck->internal_transfer_id===null,409,
            'Для оплаты счёта допускается только внешнее подтверждённое поступление');
        $linked=DB::table('invoices')->whereIn('id',$ids)->get();
        abort_unless($linked->count()===count($ids),422,'Счёт не найден');
        foreach ($linked as $i) ClientsGateway::period((int)$i->reporting_period_id,(int)$i->client_id,true);

        $claims=DB::transaction(function() use ($r,$receipt,$ids,$items,$linked) {
            $cash=DB::table('cash_transactions')->where('id',$receipt)->lockForUpdate()->first();
            abort_unless($cash,404,'Поступление не найдено');
            abort_unless($cash->status==='confirmed' && $cash->direction==='in'
                && $cash->internal_transfer_id===null,409,
                'Для оплаты счёта допускается только внешнее подтверждённое поступление');
            $invoices=DB::table('invoices')->whereIn('id',$ids)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $periods=$linked->pluck('reporting_period_id')->unique()->sort()->values()->all();
            foreach ($periods as $p) self::lockPeriod((int)$p);
            $used=self::cents(DB::table('payment_allocations')->where('cash_transaction_id',$receipt)->whereNull('reversed_at')->sum('amount'));
            abort_if($used + array_sum(array_column($items,'cents'))>self::cents($cash->amount),422,
                'Распределение превышает подтверждённое поступление');
            foreach ($items as $a) {
                $i=$invoices->get($a['id']);
                abort_unless($i && $i->document_status==='issued',409,'Нельзя оплачивать черновик');
                $paid=self::cents(DB::table('payment_allocations')->where('invoice_id',$i->id)->whereNull('reversed_at')->sum('amount'));
                abort_if($paid+$a['cents']>self::cents($i->amount),422,'Превышена сумма счёта');
                DB::table('payment_allocations')->insert([
                    'cash_transaction_id'=>$receipt,'invoice_id'=>$i->id,'amount'=>$a['cents']/100,
                    'created_by'=>self::actor($r),'created_at'=>now(),'updated_at'=>now()
                ]);
                self::audit($r,'invoice',(int)$i->id,'payment_allocated',['cash_id'=>$receipt,'kopecks'=>$a['cents']]);
            }
            $ready=[];
            foreach ($periods as $p) {
                DB::table('finance_period_settlements')->where('reporting_period_id',$p)->increment('ledger_version');
                $claim=self::settlementIfPaid((int)$p,$r);
                if ($claim) $ready[]=$claim;
            }
            return $ready;
        });
        $result=[];
        foreach ($claims as $claim) {
            $result[]=['period'=>$claim['period'],'synchronized'=>self::publishPaid($r,$claim)];
        }
        return response()->json(['data'=>['allocated'=>count($items),'settlements'=>$result]],201);
    }

    public function reverseAllocation(Request $r,int $allocation)
    {
        self::authorize($r,'finance.invoices.manage');
        $data=$r->validate(['reason'=>['required','string','min:3','max:500']]);
        $current=DB::table('payment_allocations as a')
            ->join('invoices as i','i.id','=','a.invoice_id')
            ->where('a.id',$allocation)
            ->select('i.client_id','i.reporting_period_id')->first();
        abort_unless($current,404,'Распределение не найдено');
        ClientsGateway::period((int)$current->reporting_period_id,(int)$current->client_id,true);
        return DB::transaction(function()use($r,$allocation,$data) {
            $row=DB::table('payment_allocations')->where('id',$allocation)->lockForUpdate()->first();
            abort_unless($row,404);
            abort_if($row->reversed_at!==null,409,'Распределение уже отменено');
            $invoice=DB::table('invoices')->where('id',$row->invoice_id)->lockForUpdate()->first();
            $period=self::lockPeriod((int)$invoice->reporting_period_id);
            DB::table('payment_allocations')->where('id',$allocation)->update([
                'reversed_at'=>now(),'reversed_by'=>self::actor($r),
                'reversal_reason'=>$data['reason'],'updated_at'=>now()
            ]);
            DB::table('finance_period_settlements')->where('id',$period->id)->increment('ledger_version');
            self::audit($r,'invoice',(int)$invoice->id,'payment_allocation_reversed',[
                'allocation_id'=>$allocation,'reason'=>$data['reason']
            ]);
            return response()->json(['data'=>['id'=>$allocation,'reversed'=>true]]);
        });
    }

    public function retry(Request $r,int $period)
    {
        self::authorize($r,'finance.cash.confirm');
        $row=DB::table('finance_period_settlements')->where('reporting_period_id',$period)->first();
        abort_unless($row && $row->status==='pending',409,'Нет ожидающей синхронизации');
        $issued=DB::table('invoices')->where('reporting_period_id',$period)
            ->where('document_status','issued')->get();
        $claim=[
            'period'=>$period, 'ledger_version'=>(int)$row->ledger_version,
            'invoice_count'=>$issued->count(),
            'paid_total_kopecks'=>$issued->sum(fn($i)=>self::cents($i->amount)),
            'evidence_id'=>$row->evidence_id,
        ];
        return response()->json(['data'=>['synchronized'=>self::publishPaid($r,$claim)]]);
    }
}
