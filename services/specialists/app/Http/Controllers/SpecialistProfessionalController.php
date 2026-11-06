<?php

namespace App\Http\Controllers;

use App\Models\AdditionalTechnology;
use App\Services\SpecialistProfessionalService;
use App\Support\EmployeesDirectory;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class SpecialistProfessionalController extends Controller
{
    public function __construct(
        private readonly EmployeesDirectory $directory,
        private readonly SpecialistProfessionalService $professional,
    ) {}

    /** Resolve Employees each request; a signed service role does not authorize a specific specialist. */
    private function inScope(Request $request, int $employee): array
    {
        abort_if($request->header('X-Irlix-Role-Preview') !== null, 403);
        try {
            $directory = $this->directory->resolve($request);
        } catch (\DomainException $e) {
            abort(403, $e->getMessage());
        } catch (\RuntimeException $e) {
            abort(503, $e->getMessage());
        }
        $visible = collect($directory['employees'] ?? [])
            ->contains(static fn ($person) => (int) ($person['id'] ?? 0) === $employee);
        abort_unless($visible, 404, 'Специалист за пределами доступных подразделений.');
        return (array) ($directory['actor'] ?? []);
    }

    /**
     * Full snapshot keys are mandatory, but either association can be empty.
     * "required" rejects [] and prevents removing the final technology.
     */
    public static function profileUpdateRules(): array
    {
        return [
            'grade' => ['nullable', 'string', Rule::in(SpecialistProfessionalService::GRADES)],
            'primary' => ['present', 'array', 'max:100'],
            'primary.*.technology_id' => ['required', 'integer', 'min:1'],
            'primary.*.grade' => ['required', 'string', Rule::in(SpecialistProfessionalService::GRADES)],
            'secondary_ids' => ['present', 'array', 'max:300'],
            'secondary_ids.*' => ['integer', 'min:1'],
        ];
    }

    public function update(Request $request, int $employee): JsonResponse
    {
        $actor = $this->inScope($request, $employee);
        $payload = $request->validate(self::profileUpdateRules());
        $result = $this->professional->replace($employee, $payload,
            (int) ($actor['id'] ?? 0), (string) ($actor['full_name'] ?? 'Руководитель'));
        return response()->json(['data' => $result]);
    }

    public function timeline(Request $request, int $employee): JsonResponse
    {
        $this->inScope($request, $employee);
        return response()->json(['data' => $this->professional->timeline($employee)]);
    }

    public function comment(Request $request, int $employee): JsonResponse
    {
        $actor = $this->inScope($request, $employee);
        $payload = $request->validate(['text' => ['required', 'string', 'max:5000']]);
        $text = trim($payload['text']);
        if ($text === '') return response()->json(['message' => 'Комментарий не может быть пустым.'], 422);
        $event = $this->professional->comment($employee, $text,
            (int) ($actor['id'] ?? 0), (string) ($actor['full_name'] ?? 'Руководитель'));
        return response()->json(['data' => $event], 201);
    }

    /**
     * Inline additional-catalog creation for authorized profile editors.
     * Primary technology records never leak into this separate catalog.
     */
    public function quickCreate(Request $request): JsonResponse
    {
        abort_if($request->header('X-Irlix-Role-Preview') !== null, 403);
        if (filter_var(env('IRLIX_AUTHZ_JWT_ENABLED', false), FILTER_VALIDATE_BOOLEAN)
            && filter_var(env('IRLIX_SPECIALISTS_LOCAL_AUTHZ_ENABLED', false), FILTER_VALIDATE_BOOLEAN)) {
            $policy = (array) $request->attributes->get('specialists_local_permissions', []);
            $editable = ($policy['specialists.profiles.manage']['allowed'] ?? false) === true
                || ($policy['specialists.catalog.manage']['allowed'] ?? false) === true;
            abort_unless($editable, 403, 'Недостаточно прав для создания технологии.');
        }
        $payload = $request->validate(['name' => ['required', 'string', 'max:255']]);
        $name = trim($payload['name']);
        if ($name === '') return response()->json(['message' => 'Введите название технологии.'], 422);
        $find = static fn () => AdditionalTechnology::query()
            ->whereRaw('LOWER(TRIM(name)) = ?', [mb_strtolower($name)])->first();
        $existing = $find();
        if ($existing) {
            abort_unless($existing->active, 409, 'Технология с таким названием архивирована.');
            return response()->json(['data' => $existing->getAttributes(), 'created' => false]);
        }
        try {
            $technology = AdditionalTechnology::query()->create([
                'name' => $name, 'active' => true,
            ]);
        } catch (QueryException $e) {
            // A parallel editor may have inserted the same normalized name.
            $existing = $find();
            if (!$existing) throw $e;
            abort_unless($existing->active, 409, 'Технология с таким названием архивирована.');
            return response()->json(['data' => $existing->getAttributes(), 'created' => false]);
        }
        return response()->json(['data' => $technology->getAttributes(), 'created' => true], 201);
    }
}
