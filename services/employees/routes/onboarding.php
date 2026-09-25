<?php

use App\Support\EmployeeOnboardingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::post('/employees/{employee}/onboarding-email', function (Request $request, int $employee, EmployeeOnboardingService $onboarding) {
    try {
        $result = $onboarding->sendPasswordSetup($employee);
        return response()->json(['data' => $result]);
    } catch (Throwable $e) {
        report($e);
        $onboarding->markFailure($employee, $e);
        return response()->json([
            'message' => 'Не удалось отправить письмо для установки пароля.',
            'detail' => $e->getMessage(),
        ], 502);
    }
});
