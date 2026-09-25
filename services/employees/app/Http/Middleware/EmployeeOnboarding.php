<?php

namespace App\Http\Middleware;

use App\Support\EmployeeOnboardingService;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EmployeeOnboarding
{
    public function __construct(private readonly EmployeeOnboardingService $onboarding)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if ($request->method() !== 'POST' || $request->path() !== 'api/employees' || !$response instanceof JsonResponse || $response->getStatusCode() !== 201) {
            return $response;
        }

        $payload = $response->getData(true);
        $employeeId = (int) ($payload['data']['id'] ?? 0);
        if ($employeeId <= 0 || empty($payload['data']['keycloak_user_id'])) return $response;

        unset($payload['meta']['temporary_password']);

        try {
            $result = $this->onboarding->sendPasswordSetup($employeeId);
            $payload['meta']['onboarding_email'] = 'sent';
            $payload['meta']['onboarding_recipient'] = $result['recipient'];
        } catch (\Throwable $e) {
            report($e);
            $this->onboarding->markFailure($employeeId, $e);
            $payload['meta']['onboarding_email'] = 'send_failed';
            $payload['meta']['warning'] = 'Сотрудник создан, но письмо для установки пароля не отправлено. Его можно отправить повторно после настройки почты.';
        }

        $payload['data'] = (array) \Illuminate\Support\Facades\DB::table('employees')->where('id', $employeeId)->first();
        $response->setData($payload);
        return $response;
    }
}
