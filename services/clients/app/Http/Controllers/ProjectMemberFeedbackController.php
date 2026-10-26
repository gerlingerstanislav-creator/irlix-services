<?php

namespace App\Http\Controllers;

use App\Services\LegacyMemberFeedbackService;
use App\Support\MemberIdentity;
use Illuminate\Http\Request;

final class ProjectMemberFeedbackController extends Controller
{
    public function __construct(private readonly LegacyMemberFeedbackService $feedbacks) {}

    public function index(int $member)
    {
        $canonical = MemberIdentity::legacyMember($member);
        $projectId = $this->feedbacks->projectId($member);
        return response()->json(['data' => $this->feedbacks->list((int) $canonical->id, $projectId)]);
    }

    public function store(Request $request, int $member)
    {
        $canonical = MemberIdentity::legacyMember($member);
        $request->merge(['project_id' => $this->feedbacks->projectId($member)]);
        return app(ClientMembersController::class)->addFeedback($request, (int) $canonical->id);
    }
}
