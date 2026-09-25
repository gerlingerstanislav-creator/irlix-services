<?php

namespace App\Http\Controllers;

use App\Application\AbsenceService;
use App\Support\CurrentEmployee;
use App\Support\EmployeesDirectory;
use App\Support\VacationsAccess;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

final class AttachmentController extends Controller
{
    public function __construct(
        private readonly CurrentEmployee $currentEmployee,
        private readonly EmployeesDirectory $employees,
        private readonly AbsenceService $absences,
        private readonly VacationsAccess $authorization,
    ) {}

    public function index(Request $request, int $absence): JsonResponse
    {
        try {
            [$employee, $access, $target] = $this->context($request, $absence);
            $this->assertCanReadContent($access, (int) $employee['id'], (int) $target['employee_id']);

            $items = DB::table('absence_attachments')
                ->where('absence_id', $absence)
                ->select(['id', 'absence_id', 'kind', 'original_name', 'mime_type', 'size_bytes', 'uploaded_by_employee_id', 'created_at'])
                ->orderBy('id')
                ->get()
                ->map(fn ($row) => (array) $row)
                ->all();

            return response()->json(['data' => $items, 'meta' => ['count' => count($items)]]);
        } catch (DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 403);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 503);
        }
    }

    public function store(Request $request, int $absence): JsonResponse
    {
        try {
            [$employee, $access, $target] = $this->context($request, $absence);
            $actorId = (int) $employee['id'];
            $targetId = (int) $target['employee_id'];
            $this->assertCanReadContent($access, $actorId, $targetId);
            if (in_array((string) $target['status'], ['confirmed', 'rejected', 'cancelled'], true)) {
                throw new DomainException('После завершения процесса документы изменять нельзя');
            }

            $request->validate([
                'file' => ['required', 'file', 'max:10240', 'mimes:pdf,png,jpg,jpeg,doc,docx'],
                'kind' => ['nullable', 'in:application,document'],
            ]);

            $file = $request->file('file');
            if (!$file || !$file->isValid()) throw new DomainException('Не удалось загрузить файл');

            $directory = storage_path('app/vacations/attachments');
            if (!is_dir($directory) && !mkdir($directory, 0770, true) && !is_dir($directory)) {
                throw new RuntimeException('Не удалось подготовить хранилище документов');
            }

            $storedName = Str::uuid()->toString().'.'.$file->getClientOriginalExtension();
            $file->move($directory, $storedName);
            $relativePath = 'attachments/'.$storedName;

            $id = DB::table('absence_attachments')->insertGetId([
                'absence_id' => $absence,
                'kind' => (string) ($request->input('kind') ?: 'application'),
                'original_name' => $file->getClientOriginalName(),
                'mime_type' => $file->getClientMimeType() ?: 'application/octet-stream',
                'size_bytes' => filesize($directory.'/'.$storedName) ?: 0,
                'storage_path' => $relativePath,
                'uploaded_by_subject' => $this->subject($request),
                'uploaded_by_employee_id' => $actorId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $created = (array) DB::table('absence_attachments')->where('id', $id)->first();
            DB::table('absence_audit_log')->insert([
                'absence_id' => $absence,
                'event' => 'attachment_uploaded',
                'actor_subject' => $this->subject($request),
                'actor_employee_id' => $actorId,
                'before' => null,
                'after' => json_encode(['attachment_id' => $id, 'name' => $created['original_name']], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'created_at' => now(),
            ]);
            unset($created['storage_path'], $created['uploaded_by_subject']);
            return response()->json(['data' => $created], 201);
        } catch (DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 503);
        }
    }

    public function download(Request $request, int $absence, int $attachment)
    {
        try {
            [$employee, $access] = $this->context($request, $absence);
            $target = $this->absences->get($absence);
            $this->assertCanReadContent($access, (int) $employee['id'], (int) $target['employee_id']);

            $row = DB::table('absence_attachments')->where('id', $attachment)->where('absence_id', $absence)->first();
            if (!$row) return response()->json(['message' => 'Документ не найден'], 404);

            $path = storage_path('app/vacations/'.$row->storage_path);
            if (!is_file($path)) return response()->json(['message' => 'Файл документа недоступен'], 404);

            return response()->download($path, $row->original_name, ['Content-Type' => $row->mime_type]);
        } catch (DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 403);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 503);
        }
    }

    public function destroy(Request $request, int $absence, int $attachment)
    {
        try {
            [$employee, $access, $target] = $this->context($request, $absence);
            $this->assertCanReadContent($access, (int) $employee['id'], (int) $target['employee_id']);
            if (in_array((string) $target['status'], ['confirmed', 'rejected', 'cancelled'], true)) {
                throw new DomainException('После завершения процесса документы изменять нельзя');
            }

            $row = DB::table('absence_attachments')->where('id', $attachment)->where('absence_id', $absence)->first();
            if (!$row) return response()->json(['message' => 'Документ не найден'], 404);

            $actorId = (int) $employee['id'];
            $actorSubject = $this->subject($request);
            DB::transaction(function () use ($row, $attachment, $absence, $actorId, $actorSubject) {
                DB::table('absence_attachments')->where('id', $attachment)->delete();
                DB::table('absence_audit_log')->insert([
                    'absence_id' => $absence,
                    'event' => 'attachment_deleted',
                    'actor_subject' => $actorSubject,
                    'actor_employee_id' => $actorId,
                    'before' => json_encode(['attachment_id' => $attachment, 'name' => $row->original_name], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'after' => null,
                    'created_at' => now(),
                ]);
                $path = storage_path('app/vacations/'.$row->storage_path);
                if (is_file($path)) @unlink($path);
            });

            return response()->noContent();
        } catch (DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 503);
        } catch (Throwable $e) {
            report($e);
            return response()->json(['message' => 'Не удалось удалить документ'], 500);
        }
    }

    private function context(Request $request, int $absence): array
    {
        $employee = $this->currentEmployee->resolve($request);
        $access = $this->employees->access($request);
        $target = $this->absences->get($absence);
        $this->authorization->assertCanAccessEmployee($request, $access, (int) $employee['id'], (int) $target['employee_id']);
        return [$employee, $access, $target];
    }

    private function assertCanReadContent(array $access, int $actorId, int $targetId): void
    {
        if ($actorId === $targetId || $this->authorization->isHr($access)) return;
        throw new DomainException('Содержимое документов доступно только сотруднику и кадровому специалисту');
    }

    private function subject(Request $request): string
    {
        $identity = (array) $request->attributes->get('identity', []);
        return (string) ($identity['sub'] ?? '');
    }
}
