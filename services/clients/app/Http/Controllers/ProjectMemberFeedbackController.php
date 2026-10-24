<?php
namespace App\Http\Controllers;
use App\Support\MemberIdentity;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
class ProjectMemberFeedbackController extends Controller {
    public function index(int $member) {
        $m=MemberIdentity::legacyMember($member);
        $project=DB::table('project_members')->where('id',$member)->value('project_id');
        return response()->json(['data'=>DB::table('client_member_feedbacks')->where('client_member_id',$m->id)
            ->where('project_id',$project)->orderByDesc('created_at')->orderByDesc('id')->get()]);
    }
    public function store(Request $request,int $member) {
        $m=MemberIdentity::legacyMember($member);
        $request->merge(['project_id'=>DB::table('project_members')->where('id',$member)->value('project_id')]);
        return app(ClientMembersController::class)->addFeedback($request,(int)$m->id);
    }
}
