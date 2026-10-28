<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Models\EmployeeAccessRole;
use App\Support\BrandbookGate;
use App\Support\SpecialRoles;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Crypt;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/** Independent server-side validation for every Storage V1 request. */
final class StorageV1GateController extends Controller
{
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
        return response('', BrandbookGate::active(Employee::query()->find($employee)) ? 204 : 404,
                        ['Cache-Control' => 'private, no-store']);
    }
}
