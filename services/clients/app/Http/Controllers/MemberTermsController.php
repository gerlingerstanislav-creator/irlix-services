<?php

namespace App\Http\Controllers;

use App\Support\MemberIdentity;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class MemberTermsController extends Controller
{
    // Compatibility adapter: old URLs still identify a ProjectMember, while
    // commercial history belongs to the client's enduring membership.
    public function store(Request $request, int $member)
    {
        $m=MemberIdentity::legacyMember($member);
        $data=$request->validate([
            'technology'=>['required','string','max:100'],'level'=>['required','string','max:100'],
            'hourly_rate'=>['required','numeric','min:0'],'hours_per_day'=>['required','numeric','min:0','max:24'],
            'valid_from'=>['required','date'],'valid_to'=>['nullable','date','after_or_equal:valid_from'],
        ]);
        return DB::transaction(function()use($m,$member,$data){
            DB::table('clients')->where('id',$m->client_id)->lockForUpdate()->first();
            DB::table('client_members')->where('id',$m->id)->lockForUpdate()->first();
            MemberIdentity::assertPeriodEditable((int)$m->client_id,$data['valid_from'],$data['valid_to']??null);
            $previous=DB::table('member_terms')->where('client_member_id',$m->id)->whereDate('valid_from','<',$data['valid_from'])
                ->where(fn($q)=>$q->whereNull('valid_to')->orWhereDate('valid_to','>=',$data['valid_from']))->first();
            if($previous){
                MemberIdentity::assertTermChangeEditable((int)$m->client_id,$previous,[],(string)$previous->valid_from,CarbonImmutable::parse($data['valid_from'])->subDay()->toDateString());
                DB::table('member_terms')->where('id',$previous->id)->update([
                    'valid_to'=>CarbonImmutable::parse($data['valid_from'])->subDay()->toDateString(),'updated_at'=>now()]);
            }
            MemberIdentity::assertConditionsAvailable((int)$m->client_id,$m->employee_id,$m->partner_specialist_id,$data['valid_from'],$data['valid_to']??null);
            $id=DB::table('member_terms')->insertGetId([...$data,'client_member_id'=>$m->id,'project_member_id'=>$member,'created_at'=>now(),'updated_at'=>now()]);
            return response()->json(['data'=>DB::table('member_terms')->find($id)],201);
        });
    }
}
