<?php

namespace App\Http\Controllers;

use App\Services\EmployeeIdentityProvisionService;
use App\Services\EmployeeReadService;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Throwable;

/** Platform-admin-only identity recovery for imported, already employed staff. */
final class EmployeeIdentityProvisionController extends Controller
{
    public function provision(
        Request $request,
        int $employee,
        EmployeeIdentityProvisionService $provision,
        EmployeeReadService $read
    ): JsonResponse {
        $access = $request->attributes->get('employees_access', []);
        if (!in_array('platform-admin', (array) ($access['roles'] ?? []), true)
            || !($access['permissions']['access.manage'] ?? false)
            || !($access['permissions']['employees.manage'] ?? false)) {
            return response()->json(['message' => 'Требуется администратор платформы.'], 403);
        }

        try {
            $outcome = $provision->provision($employee);
        } catch (DomainException $error) {
            return response()->json(['message' => $error->getMessage()], 409);
        } catch (Throwable $error) {
            report($error);
            return response()->json([
                'message' => 'Не удалось подтвердить создание доступа в Keycloak. '
                    .'Повторное создание заблокировано до сверки с Keycloak.',
            ], 502);
        }

        return response()->json([
            'data' => $read->raw($employee),
            'meta' => $outcome,
        ]);
    }
}
