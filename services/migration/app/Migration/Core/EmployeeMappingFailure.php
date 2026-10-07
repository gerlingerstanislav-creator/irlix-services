<?php

namespace App\Migration\Core;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

final class EmployeeMappingFailure
{
    public static function response(\Throwable $error, string $stage): JsonResponse
    {
        if ($error instanceof \RuntimeException && $error->getCode() === 409) {
            return response()->json(['message' => 'Дождитесь завершения активной операции.', 'code' => 'EMPLOYEE_MAPPING_BUSY', 'stage' => $stage], 409);
        }
        $reference = (string) \Illuminate\Support\Str::uuid();
        $sqlState = null;
        for ($cause = $error; $cause; $cause = $cause->getPrevious()) {
            if ($cause instanceof \PDOException && preg_match('/^[A-Z0-9]{5}$/D', (string) $cause->getCode())) {
                $sqlState = (string) $cause->getCode();
                break;
            }
        }
        $message = match ($stage) {
            'queue' => 'Служба очереди переноса не ответила или вернула некорректный ответ. Поиск сотрудника ещё не выполнен.',
            'employee' => 'Не удалось прочитать сотрудников из БД Employees. Это техническая ошибка поиска, а не отсутствие сотрудника.',
            'source' => 'Не удалось прочитать исходного пользователя из отчёта миграции.',
            'identity' => 'Не удалось проверить сохранённое сопоставление UUID сотрудника.',
            default => 'Не удалось записать сопоставление в БД миграции. Проверьте сохранённое состояние перед повторной попыткой.',
        };
        if ($sqlState === '42501') $message .= ' Недостаточно прав доступа к БД.';
        elseif ($sqlState === '28P01' || $sqlState === '28000') $message .= ' БД отклонила учётные данные подключения.';
        elseif ($sqlState === '42P01' || $sqlState === '42703') $message .= ' Ожидаемая таблица или колонка отсутствует.';
        elseif ($sqlState !== null && str_starts_with($sqlState, '08')) $message .= ' Соединение с БД недоступно.';
        $code = 'EMPLOYEE_MAPPING_'.strtoupper($stage).'_FAILED';
        // Never log exception messages, SQL, bindings, credentials or employee identities.
        Log::error('Employee mapping technical failure', ['diagnostic_id' => $reference, 'stage' => $stage, 'code' => $code, 'exception_type' => get_class($error), 'sqlstate' => $sqlState]);
        return response()->json(['message' => $message.' Код диагностики: '.$reference, 'code' => $code, 'stage' => $stage, 'diagnostic_id' => $reference, 'sqlstate' => $sqlState], 503);
    }
}
