<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Models\EmployeeAccessRole;
use App\Support\BrandbookGate;
use App\Support\EmployeesAccess;
use App\Support\SpecialRoles;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Crypt;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/** Independent server-side validation for every Storage V1 request. */
final class StorageV1GateController extends Controller
{
    public function __construct(private readonly EmployeesAccess $access) {}

    private function identity(Request $request): array
    {
        if (!preg_match('/(?:^|;\\s*)irlix_storage_v1=([^;]*)/',
            (string) $request->header('Cookie', ''), $match)) {
            return ['status' => 401];
        }
        try {
            $session = json_decode(Crypt::decryptString(rawurldecode($match[1])), true, 8, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return ['status' => 401];
        }
        if (!BrandbookGate::validSession($session)) return ['status' => 401];
        try {
            $employee = Employee::query()->whereKey($session['employee_id'])
                ->where('keycloak_user_id', $session['subject'])->first();
            if (!BrandbookGate::active($employee)) return ['status' => 403];
            // Recompute effective service permission from current Employees roles
            // and overrides every time, rather than trusting a 5-minute snapshot.
            $effectiveRequest = Request::create('/_storage_v1_access', 'GET');
            $effectiveRequest->attributes->set('identity', ['sub' => $session['subject']]);
            $access = $this->access->resolve($effectiveRequest);
            if (($access['services']['storage'] ?? false) !== true
                || (int) ($access['employee_id'] ?? 0) !== (int) $employee->id) {
                return ['status' => 403];
            }
            $admin = EmployeeAccessRole::query()->where('employee_id', $employee->id)
                ->where('role', SpecialRoles::PlatformAdmin)->exists();
            return ['status' => 204, 'employee_id' => (int) $employee->id, 'admin' => $admin];
        } catch (Throwable) {
            return ['status' => 503];
        }
    }

    public function check(Request $request): Response
    {
        $result = $this->identity($request);
        $headers = ['Cache-Control' => 'private, no-store', 'Vary' => 'Cookie'];
        if ($result['status'] === 204) {
            $headers['X-Storage-Employee-ID'] = (string) $result['employee_id'];
            $headers['X-Storage-Platform-Admin'] = $result['admin'] ? '1' : '0';
        }
        return response('', $result['status'], $headers);
    }

    public function recipient(Request $request, int $employee): Response
    {
        $actor = $this->identity($request);
        if ($actor['status'] !== 204) return response('', $actor['status']);
        $target = Employee::query()->find($employee);
        if (!BrandbookGate::active($target)) return response('', 404);
        $context = Request::create('/_storage_v1_recipient', 'GET');
        $context->attributes->set('identity', ['sub' => (string) $target->keycloak_user_id]);
        $rights = $this->access->resolve($context);
        return response('', ($rights['services']['storage'] ?? false) === true ? 204 : 404,
                        ['Cache-Control' => 'private, no-store']);
    }
}
