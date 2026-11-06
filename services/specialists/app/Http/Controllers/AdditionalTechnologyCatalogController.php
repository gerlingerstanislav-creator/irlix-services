<?php

namespace App\Http\Controllers;

use App\Services\AdditionalTechnologyCatalogService;
use App\Support\SpecialistsCatalogAuthorizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

final class AdditionalTechnologyCatalogController extends Controller
{
    public function __construct(
        private readonly SpecialistsCatalogAuthorizer $authorization,
        private readonly AdditionalTechnologyCatalogService $catalog,
    ) {}

    public function rename(Request $request, int $technology): JsonResponse
    {
        $actor = $this->authorization->assertCanManage($request);
        $input = $request->validate(['name' => ['required', 'string', 'max:255']]);
        $result = $this->catalog->rename($technology, $input['name'],
            (int) ($actor['employee_id'] ?? $actor['id'] ?? 0),
            (string) ($actor['full_name'] ?? $actor['name'] ?? 'Администратор каталога'));
        return response()->json(['data' => $result]);
    }

    public function remove(Request $request, int $technology): JsonResponse
    {
        // Always check global catalog.manage independently of profile edit scope.
        $actor = $this->authorization->assertCanManage($request);
        $input = $request->validate([
            'replacement_id' => ['nullable', 'integer', 'min:1'],
        ]);
        $replacementId = isset($input['replacement_id']) ? (int) $input['replacement_id'] : null;

        $result = $this->catalog->removeOrReplace(
            $technology, $replacementId,
            (int) ($actor['employee_id'] ?? $actor['id'] ?? 0),
            (string) ($actor['full_name'] ?? $actor['name'] ?? 'Администратор каталога')
        );
        return response()->json(['data' => $result]);
    }
}
