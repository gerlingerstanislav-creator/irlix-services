<?php
namespace App\Http\Controllers;

use App\Services\AttentionService;
use App\Support\CurrentEmployee;
use Illuminate\Http\Request;

final class AttentionController
{
    public function __invoke(Request $request, CurrentEmployee $actor, AttentionService $service)
    {
        return response()->json(['data' => $service->tasks($request, $actor->resolve($request))])
            ->header('Cache-Control', 'no-store');
    }
}
