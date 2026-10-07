<?php

namespace App\Migration\Core;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

final class EmployeeMappingFailure
{
    public static function sqlState(\Throwable $error): ?string
    {
        for ($cause = $error; $cause; $cause = $cause->getPrevious()) {
            $state = $cause instanceof \PDOException ? ($cause->errorInfo[0] ?? $cause->getCode()) : null;
            if (preg_match('/^[A-Z0-9]{5}$/D', (string) $state)) return (string) $state;
        }
        return null;
    }

    public static function category(\Throwable $error): string
    {
        for ($cause = $error; $cause; $cause = $cause->getPrevious()) {
            $message = strtolower($cause->getMessage());
            if (str_contains($message, 'could not find driver')) return 'DB_DRIVER_MISSING';
            if (str_contains($message, 'not configured')) return 'DB_CONNECTION_NOT_CONFIGURED';
            if (str_contains($message, 'getaddrinfo') || str_contains($message, 'could not translate host name')) return 'DB_HOST_UNRESOLVED';
        }
        return 'DB_QUERY_FAILED';
    }

    public static function response(\Throwable $error, string $stage): JsonResponse
    {
        if ($error instanceof \RuntimeException && $error->getCode() === 409) {
            return response()->json(['message' => 'Дождитесь завершения активной операции.', 'code' => 'EMPLOYEE_MAPPING_BUSY', 'stage' => $stage], 409);
        }
        $reference = (string) \Illuminate\Support\Str::uuid();
        $sqlState = self::sqlState($error);
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
        $category = self::category($error);
        if ($stage === 'employee' && $category === 'DB_DRIVER_MISSING') $message .= ' В PHP отсутствует драйвер БД.';
        elseif ($stage === 'employee' && $category === 'DB_CONNECTION_NOT_CONFIGURED') $message .= ' Подключение target_employees не настроено.';
        elseif ($stage === 'employee' && $category === 'DB_HOST_UNRESOLVED') $message .= ' Имя сервера БД не разрешается.';
        $code = 'EMPLOYEE_MAPPING_'.strtoupper($stage).'_FAILED';
        // Never log exception messages, SQL, bindings, credentials or employee identities.
        Log::error('Employee mapping technical failure', ['diagnostic_id' => $reference, 'stage' => $stage, 'code' => $code, 'exception_type' => get_class($error), 'sqlstate' => $sqlState, 'category' => $category]);
        return response()->json(['message' => $message.' Код диагностики: '.$reference, 'code' => $code, 'stage' => $stage, 'diagnostic_id' => $reference, 'sqlstate' => $sqlState, 'category' => $category], 503);
    }
}
