<?php

namespace App\Http\Controllers;

use App\Services\LegacyMemberCardService;
use Illuminate\Http\Request;

final class ProjectMemberCardController extends Controller
{
    public function show(int $member)
    {
        return response()->json(['data' => app(LegacyMemberCardService::class)->payload($member)]);
    }

    public function update(Request $request, int $member)
    {
        // Legacy project-pointer updates keep their existing approval and provenance checks.
        return app(ClientsController::class)->moveMember($request, $member);
    }
}
