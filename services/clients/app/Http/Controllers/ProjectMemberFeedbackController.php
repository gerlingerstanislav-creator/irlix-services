<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ProjectMemberFeedbackController extends Controller
{
    public function index(int $member)
    {
        abort_unless(DB::table('project_members')->where('id', $member)->exists(), 404, 'Project member not found');

        return response()->json([
            'data' => DB::table('project_member_feedbacks')
                ->where('project_member_id', $member)
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->get(),
        ]);
    }

    public function store(Request $request, int $member)
    {
        abort_unless(DB::table('project_members')->where('id', $member)->exists(), 404, 'Project member not found');

        $data = $request->validate([
            'text' => ['required', 'string', 'max:10000'],
        ]);
        $text = trim($data['text']);
        abort_if($text === '', 422, 'Feedback text is required');

        $identity = $request->attributes->get('identity', []);
        $id = DB::table('project_member_feedbacks')->insertGetId([
            'project_member_id' => $member,
            'text' => $text,
            'created_by_username' => $identity['preferred_username'] ?? null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json([
            'data' => DB::table('project_member_feedbacks')->find($id),
        ], 201);
    }
}
