<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class CashTransferController extends Controller {
    private static function actor(Request $r): string {
        return (string)($r->attributes->get('identity')['sub'] ?? 'unknown');
    }
    public function index(Request $r) {
        abort_unless(($r->attributes->get('identity')['permissions']['finance.view']??false)===true,403);
        return response()->json(['data'=>DB::table('cash_transfers')->orderByDesc('id')->limit(500)->get()]);
    }
    public function create(Request $r) {
        abort_unless(($r->attributes->get('identity')['permissions']['finance.cash.confirm']??false)===true,403);
        $v=$r->validate([
            'request_id'=>['required','uuid'],
            'source_account_id'=>['required','integer','min:1'],
            'destination_account_id'=>['required','integer','min:1','different:source_account_id'],
            'occurred_on'=>['required','date'],
            'amount'=>['required','numeric','gt:0','decimal:0,2'],
        ]);
        // Client retries with the same idempotency key cannot move a second pair.
        $existing=DB::table('cash_transfers')->where('request_id',$v['request_id'])->first();
        if($existing)return self::already($existing,$v);
        try {
            return DB::transaction(function()use($r,$v){
                $accounts=DB::table('financial_accounts')
                    ->whereIn('id',[$v['source_account_id'],$v['destination_account_id']])
                    ->orderBy('id')->lockForUpdate()->get()->keyBy('id');
                abort_unless($accounts->count()===2,422,'Один из собственных счетов не найден');
                foreach($accounts as $a){
                    abort_unless($a->active && $a->currency==='RUB',422,'Перевод доступен между активными рублёвыми счетами');
                    abort_unless(substr((string)$a->opening_on,0,10)<=$v['occurred_on'],422,
                        'Дата перевода предшествует начальному остатку счета');
                }
                $actor=self::actor($r);
                $id=DB::table('cash_transfers')->insertGetId([
                    'request_id'=>$v['request_id'],
                    'source_account_id'=>$v['source_account_id'],
                    'destination_account_id'=>$v['destination_account_id'],
                    'occurred_on'=>$v['occurred_on'],'amount'=>$v['amount'],
                    'created_by'=>$actor,'created_at'=>now(),'updated_at'=>now(),
                ]);
                foreach([
                    [$v['source_account_id'],'out'],
                    [$v['destination_account_id'],'in']
                ] as [$accountId,$direction]){
                    DB::table('cash_transactions')->insert([
                        'financial_account_id'=>$accountId,
                        'occurred_on'=>$v['occurred_on'],
                        'direction'=>$direction,
                        'amount'=>$v['amount'],
                        'status'=>'confirmed',
                        'flow_type'=>'internal_transfer',
                        'internal_transfer_id'=>$id,
                        'request_id'=>(string)Str::uuid(),
                        'created_by'=>$actor,'confirmed_by'=>$actor,
                        'confirmed_at'=>now(),'created_at'=>now(),'updated_at'=>now(),
                    ]);
                }
                DB::table('finance_audit_log')->insert([
                    'entity_type'=>'cash_transfer','entity_id'=>$id,'action'=>'created',
                    'actor'=>$actor,
                    'details'=>json_encode([
                        'source_account_id'=>$v['source_account_id'],
                        'destination_account_id'=>$v['destination_account_id'],
                        'amount'=>$v['amount'],
                    ]),
                    'created_at'=>now(),
                ]);
                return response()->json(['data'=>DB::table('cash_transfers')->find($id)],201);
            });
        }catch(\Illuminate\Database\QueryException $e){
            if($e->getCode()==='23505'){
                $same=DB::table('cash_transfers')->where('request_id',$v['request_id'])->first();
                return $same?self::already($same,$v):response()->json(['message'=>'Конфликт идентификатора перевода'],409);
            }
            throw $e;
        }
    }
    private static function already(object $row,array $v){
        $same=(int)$row->source_account_id===(int)$v['source_account_id']
            && (int)$row->destination_account_id===(int)$v['destination_account_id']
            && substr((string)$row->occurred_on,0,10)===$v['occurred_on']
            && (int)round((float)$row->amount*100)===(int)round((float)$v['amount']*100);
        return $same?response()->json(['data'=>$row])
            :response()->json(['message'=>'Идентификатор перевода повторно использован с другими данными'],409);
    }
}
