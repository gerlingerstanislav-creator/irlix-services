<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

final class RequestWorkflowController extends Controller
{
    public const ATTEMPT_STATUSES = [
        'Новая', 'CV отправлено', 'Интервью назначено', 'Интервью пройдено',
        'Ожидает подключения', 'Закрыт: успех', 'Закрыт: неудача',
    ];

    public const FAILURE_REASONS = [
        'Запрос закрыт',
        'Заведомо не подходил по уровню',
        'Интервью: отрицательная ОС',
        'Отказ специалиста',
        'CV: Недостаточно отраслевого опыта',
        'Специалист уволился',
        'Интервью: Положительная ОС без подключения',
        'CV: Нет ОС',
        'Интервью: отменено',
        'CV: Недостаточно ком. опыта',
        'CV: Не пройдено',
        'CV: Недостаточно информации для положительного ответа',
        'CV: Нет опыта в требующейся технологии',
        'Подключение не состоялось',
        'Интервью: не прошел тестовое',
    ];

    private const REQUEST_STATUSES = ['Новый', 'В работе', 'Закрыт: успех', 'Закрыт: неудача'];
    private const POSITION_STATUSES = ['Ждёт кандидатов', 'На рассмотрении', 'Частично закрыта', 'Закрыта: успех', 'Закрыта: неудача'];
    private const INTERVIEW_RATINGS = ['Положительно', 'Нейтрально', 'Отрицательно'];

    public function updateRequest(Request $request, int $clientRequest)
    {
        $this->assertAccountManager($request);
        $this->assertEntity('client_requests', $clientRequest, 'Запрос не найден.');
        $data = $request->validate([
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string'],
            'responsible_employee_id' => ['sometimes', 'required', 'integer', 'min:1'],
            'deadline' => ['sometimes', 'nullable', 'date'],
            'status' => ['sometimes', 'required', Rule::in(self::REQUEST_STATUSES)],
        ]);
        DB::table('client_requests')->where('id', $clientRequest)->update([...$data, 'updated_at' => now()]);
        return response()->json(['data' => DB::table('client_requests')->find($clientRequest)]);
    }

    public function updatePosition(Request $request, int $position)
    {
        $this->assertAccountManager($request);
        $this->assertEntity('positions', $position, 'Позиция не найдена.');
        $data = $request->validate([
            'direction' => ['sometimes', 'nullable', 'string', 'max:100'],
            'technology' => ['sometimes', 'required', 'string', 'max:100'],
            'level' => ['sometimes', 'required', 'string', 'max:100'],
            'quantity' => ['sometimes', 'required', 'integer', 'min:1', 'max:100'],
            'description' => ['sometimes', 'nullable', 'string'],
            'status' => ['sometimes', 'required', Rule::in(self::POSITION_STATUSES)],
        ]);
        DB::table('positions')->where('id', $position)->update([...$data, 'updated_at' => now()]);
        return response()->json(['data' => DB::table('positions')->find($position)]);
    }

    public function storeAttempt(Request $request, int $position)
    {
        $this->assertDirectionManager($request);
        $this->assertEntity('positions', $position, 'Позиция не найдена.');
        $data = $request->validate([
            'specialist_id' => ['required', 'integer', 'min:1'],
            'specialist_name' => ['required', 'string', 'max:255'],
            'responsible_employee_id' => ['nullable', 'integer', 'min:1'],
            'control_date' => ['nullable', 'date'],
            'proposed_rate' => ['nullable', 'numeric', 'min:0'],
            'description' => ['nullable', 'string', 'max:5000'],
            'cv' => ['required', 'file', 'max:15360', 'mimes:pdf,doc,docx'],
        ]);
        $file = $request->file('cv');
        abort_unless($file?->isValid(), 422, 'Не удалось загрузить CV.');
        $directory = storage_path('app/clients/cv');
        abort_if(!is_dir($directory) && !mkdir($directory, 0770, true) && !is_dir($directory), 503, 'Не удалось подготовить хранилище CV.');
        $originalName = $file->getClientOriginalName();
        $mimeType = $file->getClientMimeType() ?: 'application/octet-stream';
        $sizeBytes = $file->getSize() ?: 0;
        $storedName = Str::uuid()->toString().'.'.$file->getClientOriginalExtension();
        $file->move($directory, $storedName);

        $id = DB::transaction(function () use ($data, $position, $originalName, $mimeType, $sizeBytes, $storedName): int {
            $id = DB::table('connection_attempts')->insertGetId([
                'position_id' => $position,
                'specialist_id' => $data['specialist_id'],
                'specialist_name' => $data['specialist_name'],
                'responsible_employee_id' => $data['responsible_employee_id'] ?? null,
                'control_date' => $data['control_date'] ?? null,
                'proposed_rate' => $data['proposed_rate'] ?? null,
                'description' => $data['description'] ?? null,
                'status' => 'Новая',
                'cv_original_name' => $originalName,
                'cv_mime_type' => $mimeType,
                'cv_size_bytes' => $sizeBytes,
                'cv_storage_path' => $storedName,
                'cv_uploaded_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            DB::table('positions')->where('id', $position)->where('status', 'Ждёт кандидатов')->update(['status' => 'На рассмотрении', 'updated_at' => now()]);
            return $id;
        });
        return response()->json(['data' => $this->attempt($id)], 201);
    }

    public function updateAttempt(Request $request, int $attempt)
    {
        $row = $this->attemptRow($attempt);
        abort_if($this->isClosed($row->status), 422, 'Закрытую попытку изменять нельзя.');
        $this->assertDirectionManagerBeforeCv($request, $row->status);
        $data = $request->validate([
            'control_date' => ['sometimes', 'nullable', 'date'],
            'proposed_rate' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'responsible_employee_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
        ]);
        DB::table('connection_attempts')->where('id', $attempt)->update([...$data, 'updated_at' => now()]);
        return response()->json(['data' => $this->attempt($attempt)]);
    }

    public function downloadCv(Request $request, int $attempt)
    {
        $row = $this->attemptRow($attempt);
        abort_unless($row->cv_storage_path, 404, 'CV не найдено.');
        $path = storage_path('app/clients/cv/'.$row->cv_storage_path);
        abort_unless(is_file($path), 404, 'Файл CV недоступен.');
        return response()->download($path, $row->cv_original_name, ['Content-Type' => $row->cv_mime_type ?: 'application/octet-stream']);
    }

    public function sendCv(Request $request, int $attempt)
    {
        $this->assertAccountManager($request);
        $row = $this->attemptRow($attempt);
        abort_unless($row->status === 'Новая', 422, 'CV можно отправить только для новой попытки.');
        abort_unless($row->cv_storage_path, 422, 'К попытке не прикреплено CV.');
        DB::table('connection_attempts')->where('id', $attempt)->update(['status' => 'CV отправлено', 'cv_sent_at' => now(), 'updated_at' => now()]);
        return response()->json(['data' => $this->attempt($attempt)]);
    }

    public function scheduleInterview(Request $request, int $attempt)
    {
        $this->assertAccountManager($request);
        $row = $this->attemptRow($attempt);
        abort_unless(in_array($row->status, ['CV отправлено', 'Интервью назначено', 'Интервью пройдено'], true), 422, 'Из текущего статуса интервью назначить нельзя.');
        $data = $request->validate(['scheduled_at' => ['required', 'date']]);
        DB::transaction(function () use ($attempt, $data): void {
            $sequence = ((int) DB::table('attempt_interviews')->where('connection_attempt_id', $attempt)->max('sequence')) + 1;
            DB::table('attempt_interviews')->insert([
                'connection_attempt_id' => $attempt,
                'sequence' => $sequence,
                'scheduled_at' => $data['scheduled_at'],
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            DB::table('connection_attempts')->where('id', $attempt)->update(['status' => 'Интервью назначено', 'updated_at' => now()]);
        });
        return response()->json(['data' => $this->attempt($attempt)]);
    }

    public function completeInterview(Request $request, int $attempt, int $interview)
    {
        $this->assertAccountManager($request);
        $row = $this->attemptRow($attempt);
        abort_unless($row->status === 'Интервью назначено', 422, 'Сейчас нет назначенного интервью.');
        $interviewRow = DB::table('attempt_interviews')->where('id', $interview)->where('connection_attempt_id', $attempt)->first();
        abort_unless($interviewRow, 404, 'Интервью не найдено.');
        abort_if($interviewRow->completed_at, 422, 'Итоги интервью уже заполнены.');
        $data = $request->validate([
            'rating' => ['required', Rule::in(self::INTERVIEW_RATINGS)],
            'feedback' => ['required', 'string', 'max:1000'],
        ]);
        DB::transaction(function () use ($attempt, $interview, $data): void {
            DB::table('attempt_interviews')->where('id', $interview)->update([...$data, 'completed_at' => now(), 'updated_at' => now()]);
            $hasPending = DB::table('attempt_interviews')->where('connection_attempt_id', $attempt)->whereNull('completed_at')->exists();
            DB::table('connection_attempts')->where('id', $attempt)->update(['status' => $hasPending ? 'Интервью назначено' : 'Интервью пройдено', 'updated_at' => now()]);
        });
        return response()->json(['data' => $this->attempt($attempt)]);
    }

    public function scheduleConnection(Request $request, int $attempt)
    {
        $this->assertAccountManager($request);
        $row = $this->attemptRow($attempt);
        abort_unless($row->status === 'Интервью пройдено', 422, 'Подключение можно назначить после пройденного интервью.');
        $data = $request->validate(['connection_date' => ['required', 'date']]);
        DB::table('connection_attempts')->where('id', $attempt)->update(['status' => 'Ожидает подключения', 'connection_date' => $data['connection_date'], 'updated_at' => now()]);
        return response()->json(['data' => $this->attempt($attempt)]);
    }

    public function closeFailure(Request $request, int $attempt)
    {
        $row = $this->attemptRow($attempt);
        abort_if($this->isClosed($row->status), 422, 'Попытка уже закрыта.');
        if ($row->status === 'Новая') $this->assertDirectionManager($request); else $this->assertAccountManager($request);
        $data = $request->validate(['reasons' => ['required', 'array', 'min:1'], 'reasons.*' => ['string', Rule::in(self::FAILURE_REASONS)]]);
        $reasons = array_values(array_unique($data['reasons']));
        foreach ($reasons as $reason) {
            if (str_starts_with($reason, 'CV:')) abort_unless($row->status === 'CV отправлено', 422, 'Причины CV доступны только в статусе «CV отправлено».');
            if (str_starts_with($reason, 'Интервью:')) abort_unless($row->status === 'Интервью назначено', 422, 'Причины интервью доступны только в статусе «Интервью назначено».');
        }
        DB::table('connection_attempts')->where('id', $attempt)->update([
            'status' => 'Закрыт: неудача',
            'failure_reasons' => json_encode($reasons, JSON_UNESCAPED_UNICODE),
            'closed_at' => now(),
            'updated_at' => now(),
        ]);
        return response()->json(['data' => $this->attempt($attempt)]);
    }

    private function attempt(int $id): array
    {
        $row = (array) $this->attemptRow($id);
        unset($row['cv_storage_path']);
        $row['failure_reasons'] = json_decode((string) ($row['failure_reasons'] ?? '[]'), true) ?: [];
        $row['interviews'] = DB::table('attempt_interviews')->where('connection_attempt_id', $id)->orderBy('sequence')->get()->map(fn ($item) => (array) $item)->all();
        return $row;
    }

    private function attemptRow(int $attempt): object
    {
        $row = DB::table('connection_attempts')->find($attempt);
        abort_unless($row, 404, 'Попытка не найдена.');
        return $row;
    }

    private function assertEntity(string $table, int $id, string $message): void
    {
        abort_unless(DB::table($table)->where('id', $id)->exists(), 404, $message);
    }

    private function access(Request $request): array
    {
        return (array) $request->attributes->get('client_contour_access', []);
    }

    private function assertDirectionManager(Request $request): void
    {
        $access = $this->access($request);
        abort_unless(($access['platform_admin'] ?? false) || in_array('department-manager', $access['roles'] ?? [], true), 403, 'Действие доступно руководителю направления.');
    }

    private function assertAccountManager(Request $request): void
    {
        $access = $this->access($request);
        $roles = $access['roles'] ?? [];
        abort_unless(($access['platform_admin'] ?? false) || count(array_intersect(['account-manager', 'accounting-head', 'client-service-head'], $roles)) > 0, 403, 'Действие доступно аккаунт-менеджеру.');
    }

    private function assertDirectionManagerBeforeCv(Request $request, string $status): void
    {
        abort_unless($status === 'Новая', 422, 'После отправки CV попытку ведёт аккаунт-менеджер.');
        $this->assertDirectionManager($request);
    }

    private function isClosed(string $status): bool
    {
        return in_array($status, ['Закрыт: успех', 'Закрыт: неудача'], true);
    }
}
