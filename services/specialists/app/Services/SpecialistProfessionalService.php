<?php

namespace App\Services;

use App\Models\SpecialistProfile;
use App\Models\SpecialistTechnology;
use App\Models\SpecialistAdditionalTechnology;
use App\Models\AdditionalTechnology;
use App\Models\SpecialistTimelineEvent;
use App\Models\Technology;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class SpecialistProfessionalService
{
    public const GRADES = ['Junior', 'Middle', 'Middle+', 'Senior'];

    /** Read only Specialists-owned professional fields, never foreign HR facts. */
    public function technologies(int $employee): array
    {
        return SpecialistTechnology::query()->from('specialist_technologies as st')
            ->join('technologies as t', 't.id', '=', 'st.technology_id')
            ->where('st.employee_id', $employee)
            ->where('st.kind', 'primary')
            ->select(['t.id', 't.name', 't.category', 'st.kind', 'st.grade'])
            ->orderBy('t.name')
            ->get()->map(static fn ($row): array => (array) $row->getRawOriginal())->all();
    }

    /** Separate shared catalog: additional technology IDs never refer to main technologies. */
    public function additionalTechnologies(int $employee): array
    {
        return SpecialistAdditionalTechnology::query()->from('specialist_additional_technologies as st')
            ->join('additional_technologies as t', 't.id', '=', 'st.additional_technology_id')
            ->where('st.employee_id', $employee)
            ->select(['t.id', 't.name'])
            ->orderBy('t.name')->get()
            ->map(static fn ($row): array => (array) $row->getRawOriginal())->all();
    }

    /**
     * Atomic full replacement of two independent associations. Each catalog
     * has its own IDs; the same name can be main and extra, but neither
     * association is allowed to contain duplicate IDs.
     * Serialize concurrent editors by locking the employee's profile row.
     */
    public function replace(int $employee, array $input, int $actor, string $author): array
    {
        $primary = (array) ($input['primary'] ?? []);
        $secondary = array_map('intval', (array) ($input['secondary_ids'] ?? []));
        $primaryIds = array_map(static fn (array $row): int => (int) $row['technology_id'], $primary);
        if (count($primaryIds) !== count(array_unique($primaryIds))
            || count($secondary) !== count(array_unique($secondary))) {
            throw ValidationException::withMessages(['technologies' => ['Технологии не должны повторяться внутри списка.']]);
        }
        foreach ($primary as $row) {
            if (!in_array($row['grade'], self::GRADES, true)) {
                throw ValidationException::withMessages(['primary' => ['Для основной технологии выберите доступный грейд.']]);
            }
        }
        if ($primaryIds && Technology::query()->whereIn('id', $primaryIds)
            ->where('active', true)->count() !== count($primaryIds)) {
            throw ValidationException::withMessages(['primary' => ['Некоторые основные технологии недоступны.']]);
        }
        if ($secondary && AdditionalTechnology::query()->whereIn('id', $secondary)
            ->where('active', true)->count() !== count($secondary)) {
            throw ValidationException::withMessages(['secondary_ids' => ['Некоторые дополнительные технологии недоступны.']]);
        }
        // Names may exist in both global catalogs for different employees,
        // but may not be assigned twice to the same person under two labels.
        if ($primaryIds && $secondary) {
            $mainNames = Technology::query()->whereIn('id', $primaryIds)->pluck('name')
                ->map(static fn ($name) => mb_strtolower(trim((string) $name)))->all();
            $extraNames = AdditionalTechnology::query()->whereIn('id', $secondary)->pluck('name')
                ->map(static fn ($name) => mb_strtolower(trim((string) $name)))->all();
            if (array_intersect($mainNames, $extraNames)) {
                throw ValidationException::withMessages(['technologies' => [
                    'Одна технология не может быть одновременно основной и дополнительной у одного специалиста.',
                ]]);
            }
        }

        return DB::transaction(function () use ($employee, $input, $primary, $secondary, $actor, $author): array {
            SpecialistProfile::query()->firstOrCreate(['employee_id' => $employee]);
            $profile = SpecialistProfile::query()->where('employee_id', $employee)->lockForUpdate()->firstOrFail();
            $before = $this->technologies($employee);
            $beforeAdditional = $this->additionalTechnologies($employee);
            $gradeBefore = $profile->grade;
            if (array_key_exists('grade', $input)) {
                $profile->grade = $input['grade'];
                $profile->save();
            }
            SpecialistTechnology::query()->where('employee_id', $employee)->delete();
            SpecialistAdditionalTechnology::query()->where('employee_id', $employee)->delete();
            $time = now();
            $rows = [];
            foreach ($primary as $item) $rows[] = [
                'employee_id' => $employee, 'technology_id' => (int) $item['technology_id'],
                'kind' => 'primary', 'grade' => $item['grade'],
                'created_at' => $time, 'updated_at' => $time,
            ];
            if ($rows) SpecialistTechnology::query()->insert($rows);
            $extraRows = [];
            foreach ($secondary as $id) $extraRows[] = [
                'employee_id' => $employee, 'additional_technology_id' => $id,
                'created_at' => $time, 'updated_at' => $time,
            ];
            if ($extraRows) SpecialistAdditionalTechnology::query()->insert($extraRows);
            $after = $this->technologies($employee);
            $afterAdditional = $this->additionalTechnologies($employee);
            $normal = static fn (array $rows): array => collect($rows)
                ->map(static fn ($item) => implode(':', [
                    $item['id'], $item['kind'], $item['grade'] ?? '',
                ]))->sort()->values()->all();
            $normalAdditional = static fn (array $rows): array => collect($rows)
                ->map(static fn ($item) => (string) $item['id'])->sort()->values()->all();
            if ($normal($before) !== $normal($after)
                || $normalAdditional($beforeAdditional) !== $normalAdditional($afterAdditional)
                || $gradeBefore !== $profile->grade) {
                $format = static fn (array $rows): string => implode(', ', array_map(
                    static fn ($item) => $item['name'].($item['kind'] === 'primary'
                        ? ' ('.$item['grade'].')' : ' (вспом.)'), $rows));
                $formatExtra = static fn (array $rows): string => implode(', ',
                    array_map(static fn ($item) => $item['name'], $rows));
                $detail = 'Основные — было: '.($format($before) ?: 'не заполнено')
                    .'; стало: '.($format($after) ?: 'не заполнено')
                    .'. Дополнительные — было: '.($formatExtra($beforeAdditional) ?: 'не заполнено')
                    .'; стало: '.($formatExtra($afterAdditional) ?: 'не заполнено').'.';
                if ($gradeBefore !== $profile->grade) {
                    $detail .= ' Общий грейд: '.($gradeBefore ?: 'не указан')
                        .' → '.($profile->grade ?: 'не указан').'.';
                }
                $this->append($employee, 'professional', 'Изменение профессионального профиля', $detail, $actor, $author);
            }
            return ['grade' => $profile->grade, 'technologies' => $after, 'additional_technologies' => $afterAdditional];
        });
    }

    public function comment(int $employee, string $text, int $actor, string $author): array
    {
        $event = $this->append($employee, 'comment', 'Комментарий', $text, $actor, $author);
        return $this->eventData($event);
    }

    public function timeline(int $employee): array
    {
        return SpecialistTimelineEvent::query()->where('employee_id', $employee)
            ->orderBy('created_at')->orderBy('id')->get()
            ->map(fn ($row) => $this->eventData($row))->all();
    }

    private function append(int $employee, string $type, string $title, string $text, int $actor, string $author): SpecialistTimelineEvent
    {
        return SpecialistTimelineEvent::query()->create([
            'employee_id' => $employee, 'type' => $type, 'title' => $title,
            'text' => $text, 'author_employee_id' => $actor, 'author' => $author,
        ]);
    }

    private function eventData(SpecialistTimelineEvent $event): array
    {
        return [
            'id' => 'specialists-'.$event->id, 'type' => $event->type,
            'title' => $event->title, 'text' => $event->text,
            'author' => $event->author, 'created_at' => $event->created_at?->toIso8601String(),
        ];
    }
}
