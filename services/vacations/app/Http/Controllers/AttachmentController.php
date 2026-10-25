<?php

namespace App\Http\Controllers;


use App\Application\AbsenceService;
use App\Application\AttachmentService;
use App\Support\CurrentEmployee;
use App\Support\EmployeesDirectory;
use App\Support\VacationsAccess;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Throwable;

final class AttachmentController extends Controller
{
    public function __construct(
        private readonly CurrentEmployee $currentEmployee,
        private readonly EmployeesDirectory $employees,
        private readonly AbsenceService $absences,
        private readonly AttachmentService $attachments,
        private readonly VacationsAccess $authorization,
    ) {}

    public function index(Request $request, int $absence): JsonResponse
    {
        try {
            [$employee, $access, $target] = $this->context($request, $absence);
            $this->assertCanReadContent($access, (int) $employee['id'], (int) $target['employee_id']);

            $items = $this->attachments->list($absence);

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
            if (!$this->authorization->isManager($access)) {
                $this->assertCanReadContent($access, $actorId, $targetId);
            }
            if (in_array((string) $target['status'], ['confirmed', 'rejected', 'cancelled'], true)) {
                throw new DomainException('После завершения процесса документы изменять нельзя');
            }
            if ($this->attachments->exists($absence)) {
                throw new DomainException('К отсутствию можно прикрепить только один документ. Удалите текущий документ, чтобы заменить его');
            }

            $request->validate([
                'file' => ['required', 'file', 'max:10240', 'mimes:pdf,png,jpg,jpeg,doc,docx'],
                'kind' => ['nullable', 'in:application,document'],
            ]);

            $file = $request->file('file');
            if (!$file || !$file->isValid()) throw new DomainException('Не удалось загрузить файл');

            $directoryData = $this->employees->vacationsDirectory($request);
            $person = collect($directoryData['employees'] ?? [])->firstWhere('id', (int) $target['employee_id']);
            $personName = $person['full_name'] ?? (
                $actorId === $targetId ? ($employee['full_name'] ?? 'Сотрудник') : 'Сотрудник'
            );
            $created = $this->attachments->store(
                $file, $target, $actorId, $this->subject($request),
                (string) ($request->input('kind') ?: 'application'), $personName
            );

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

            $row = $this->attachments->find($absence, $attachment);
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

            $row = $this->attachments->find($absence, $attachment);
            if (!$row) return response()->json(['message' => 'Документ не найден'], 404);

            $actorId = (int) $employee['id'];
            $actorSubject = $this->subject($request);
            $this->attachments->remove($row, $actorId, $actorSubject);

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
        if ($actorId === $targetId || $this->authorization->isPersonnelOfficer($access)) return;
        throw new DomainException('Содержимое документов доступно только сотруднику и кадровику');
    }

    private function subject(Request $request): string
    {
        $identity = (array) $request->attributes->get('identity', []);
        return (string) ($identity['sub'] ?? '');
    }
}
