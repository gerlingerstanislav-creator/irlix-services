<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;

final class AccrualPaymentController extends Controller
{
    private static function actor(Request $request): string {
        return (string)($request->attributes->get('identity')['sub'] ?? 'unknown');
    }

    private static function money($value): int {
        // Input validation restricts this to two decimal places.
        return (int)round((float)$value * 100);
    }

    public function index(Request $request)
    {
        // Individual payroll settlements require detailed salary permission.
        abort_unless(($request->attributes->get('identity')['permissions']['finance.view'] ?? false)===true,403);
        abort_unless(($request->attributes->get('identity')['permissions']['finance.salary.view'] ?? false)===true,403);
        $q=DB::table('accrual_payment_links as l')
            ->join('cash_transactions as c','c.id','=','l.cash_transaction_id')
            ->join('accruals as a','a.id','=','l.accrual_id')
            ->select('l.id','l.accrual_id','l.cash_transaction_id','l.amount','l.created_at',
                'a.recognition_month','a.article','c.occurred_on','c.status as cash_status');
        if($request->filled('accrual_id')) {
            $id=filter_var($request->query('accrual_id'), FILTER_VALIDATE_INT);
            abort_unless($id!==false && $id>0,422,'Invalid accrual ID');
            $q->where('l.accrual_id',$id);
        }
        return response()->json(['data'=>$q->orderByDesc('l.id')->limit(500)->get()]);
    }

    public function attach(Request $request,int $accrual)
    {
        abort_unless(($request->attributes->get('identity')['permissions']['finance.expenses.manage'] ?? false)===true,403);
        $v=$request->validate([
            'cash_transaction_id'=>['required','integer','min:1'],
            'amount'=>['required','numeric','gt:0','decimal:0,2']
        ]);
        $cents=self::money($v['amount']);
        abort_unless($cents>0,422,'Invalid payment');
        return DB::transaction(function()use($request,$accrual,$v,$cents){
            // All writers lock the outgoing cash row first, then the accrual.
            // A transaction cannot pay two obligations more than its real amount.
            $cash=DB::table('cash_transactions')->where('id',$v['cash_transaction_id'])
                ->lockForUpdate()->first();
            abort_unless($cash,404,'Денежная операция не найдена');
            abort_unless($cash->direction==='out' && $cash->status==='confirmed'
                && $cash->internal_transfer_id===null,409,
                'Выплатой по начислению может быть только внешнее подтверждённое списание');
            $expense=DB::table('accruals')->where('id',$accrual)->lockForUpdate()->first();
            abort_unless($expense,404,'Начисление не найдено');
            if($expense->employee_id!==null || in_array($expense->kind,['salary','bonus','contribution','vacation','sick'],true))
                abort_unless(($request->attributes->get('identity')['permissions']['finance.salary.view'] ?? false)===true,
                    403,'Доступ к индивидуальным выплатам запрещён');
            abort_unless($expense->status==='confirmed',409,'Начисление не подтверждено');
            abort_if($expense->reversal_of_accrual_id!==null
                || DB::table('accruals')->where('reversal_of_accrual_id',$accrual)->exists(),
                409,'Сторнированное начисление нельзя связать с новой выплатой');
            abort_unless($expense->currency==='RUB',422,'Расчёт доступен только для RUB');
            abort_if(DB::table('accrual_payment_links')
                ->where('accrual_id',$accrual)
                ->where('cash_transaction_id',$cash->id)->exists(),409,
                'Такое списание уже прикреплено к начислению');

            $cashUsed=self::money(DB::table('accrual_payment_links')
                ->where('cash_transaction_id',$cash->id)->sum('amount'));
            $accrualUsed=self::money(DB::table('accrual_payment_links')
                ->where('accrual_id',$accrual)->sum('amount'));
            abort_if($cashUsed+$cents>self::money($cash->amount),422,
                'Сумма превышает доступный остаток денежной операции');
            abort_if($accrualUsed+$cents>self::money($expense->amount),422,
                'Сумма превышает остаток начисления');

            $id=DB::table('accrual_payment_links')->insertGetId([
                'accrual_id'=>$accrual,'cash_transaction_id'=>$cash->id,
                'amount'=>$cents/100,'created_by'=>self::actor($request),
                'created_at'=>now(),'updated_at'=>now(),
            ]);
            DB::table('finance_audit_log')->insert([
                'entity_type'=>'accrual','entity_id'=>$accrual,'action'=>'payment_linked',
                'actor'=>self::actor($request),
                'details'=>json_encode(['cash_transaction_id'=>$cash->id,'kopecks'=>$cents]),
                'created_at'=>now(),
            ]);
            // This only links a pre-existing cash movement. DO NOT create a
            // second cash_transaction or subtract it again from profit.
            return response()->json(['data'=>DB::table('accrual_payment_links')->find($id)],201);
        });
    }
    public function reverseAccrual(Request $request,int $accrual)
    {
        abort_unless(($request->attributes->get('identity')['permissions']['finance.expenses.manage'] ?? false)===true,403);
        $data=$request->validate(['reason'=>['required','string','min:3','max:500']]);
        return DB::transaction(function()use($request,$accrual,$data) {
            $original=DB::table('accruals')->where('id',$accrual)->lockForUpdate()->first();
            abort_unless($original,404,'Начисление не найдено');
            if($original->employee_id!==null || in_array($original->kind,['salary','bonus','contribution','vacation','sick'],true))
                abort_unless(($request->attributes->get('identity')['permissions']['finance.salary.view'] ?? false)===true,
                    403,'Доступ к индивидуальным начислениям запрещён');
            abort_unless($original->status==='confirmed' && $original->reversal_of_accrual_id===null,
                409,'Сторнировать можно только исходное подтверждённое начисление');
            abort_if(DB::table('accruals')->where('reversal_of_accrual_id',$accrual)->exists(),
                409,'Начисление уже сторнировано');
            // A paid obligation needs its own refund/reconciliation protocol,
            // never erase the economic claim while bank movements still exist.
            abort_if(DB::table('accrual_payment_links')->where('accrual_id',$accrual)->exists(),
                409,'Сначала необходимо согласовать корректировку уже оплаченного обязательства');

            $id=DB::table('accruals')->insertGetId([
                'recognition_month'=>$original->recognition_month,
                'kind'=>$original->kind,'article'=>$original->article,
                'cost_center'=>$original->cost_center,'employee_id'=>$original->employee_id,
                'counterparty'=>$original->counterparty,
                // Reversal must negate the identical attribution, not quietly
                // move the correction into corporate overhead.
                'client_id'=>$original->client_id,
                'project_id'=>$original->project_id,
                'direct_cost_reason'=>$original->direct_cost_reason,
                'amount'=>round(-1*(float)$original->amount,2),
                'currency'=>$original->currency,'status'=>'confirmed',
                'reversal_of_accrual_id'=>$accrual,
                'correction_reason'=>trim($data['reason']),
                'created_by'=>self::actor($request),'created_at'=>now(),'updated_at'=>now(),
            ]);
            DB::table('finance_audit_log')->insert([
                'entity_type'=>'accrual','entity_id'=>$accrual,'action'=>'reversed',
                'actor'=>self::actor($request),
                'details'=>json_encode(['reversal_id'=>$id,'reason'=>$data['reason']],JSON_UNESCAPED_UNICODE),
                'created_at'=>now(),
            ]);
            return response()->json(['data'=>['id'=>$id,'reverses'=>$accrual,'amount'=>round(-1*(float)$original->amount,2)]],201);
        });
    }

}
