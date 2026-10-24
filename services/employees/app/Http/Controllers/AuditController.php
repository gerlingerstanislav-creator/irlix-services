<?php

namespace App\Http\Controllers;

use App\Services\AuditQueryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

final class AuditController extends Controller
{
    public function __construct(private readonly AuditQueryService $audit) {}

    public function index(Request $request): JsonResponse
    {
        return response()->json($this->audit->search($request->query()));
    }
}
