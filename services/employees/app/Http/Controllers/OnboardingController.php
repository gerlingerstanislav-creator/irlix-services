<?php

namespace App\Http\Controllers;

use App\Support\EmployeeOnboardingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Throwable;

final class OnboardingController extends Controller
{
    public function __construct(private readonly EmployeeOnboardingService $onboarding) {}

    public function resend(Request $request, int $employee): JsonResponse
    {
        try {
            return response()->json(['data' => $this->onboarding->sendPasswordSetup($employee)]);
        } catch (Throwable $e) {
            report($e);
            $this->onboarding->markFailure($employee, $e);
            return response()->json([
                'message' => 'Не удалось отправить письмо для установки пароля: '.$e->getMessage(),
            ], 502);
        }
    }
}
