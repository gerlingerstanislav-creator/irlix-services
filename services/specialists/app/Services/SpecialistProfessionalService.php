<?php

namespace App\Services;

use App\Models\SpecialistProfile;
use App\Models\SpecialistTechnology;
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
            ->select(['t.id', 't.name', 't.category', 'st.kind', 'st.grade'])
            ->orderBy('t.name')
            ->get()->map(static fn ($row): array => (array) $row->getRawOriginal())->all();
    }

    /**
     * Atomic full replacement. The primary/secondary sets are disjoint; an
     * employee can have each technology only once, with grade for primary only.
     * Serialize concurrent editors by locking the employee's profile row.
     */
    public function replace(int $employee, array $input, int $actor, string $author): array
    {
        $primary = (array) ($input['primary'] ?? []);
        $secondary = array_map('intval', (array) ($input['secondary_ids'] ?? []));
        $primaryIds = array_map(static fn (array $row): int => (int) $row['technology_id'], $primary);
        $ids = [...$primaryIds, ...$secondary];
        if (count($ids) !== count(array_unique($ids))) {
            throw ValidationException::withMessages(['technologies' => ['Технология не может повторяться в основных и вспомогательных.']]);
        }
        foreach ($primary as $row) {
            if (!in_array($row['grade'], self::GRADES, true)) {
                throw ValidationException::withMessages(['primary' => ['Для основной технологии выберите доступный грейд.']]);
            }
        }
        if ($ids && Technology::query()->whereIn('id', $ids)->where('active', true)->count() !== count($ids)) {
            throw ValidationException::withMessages(['technologies' => ['Одна или несколько технологий больше не доступны.']]);
        }

        return DB::transaction(function () use ($employee, $input, $primary, $secondary, $actor, $author): array {
            SpecialistProfile::query()->firstOrCreate(['employee_id' => $employee]);
            $profile = SpecialistProfile::query()->where('employee_id', $employee)->lockForUpdate()->firstOrFail();
            $before = $this->technologies($employee);
            $gradeBefore = $profile->grade;
            if (array_key_exists('grade', $input)) {
                $profile->grade = $input['grade'];
                $profile->save();
            }
            SpecialistTechnology::query()->where('employee_id', $employee)->delete();
            $time = now();
            $rows = [];
            foreach ($primary as $item) $rows[] = [
                'employee_id' => $employee, 'technology_id' => (int) $item['technology_id'],
                'kind' => 'primary', 'grade' => $item['grade'],
                'created_at' => $time, 'updated_at' => $time,
            ];
            foreach ($secondary as $id) $rows[] = [
                'employee_id' => $employee, 'technology_id' => $id,
                'kind' => 'secondary', 'grade' => null,
                'created_at' => $time, 'updated_at' => $time,
            ];
            if ($rows) SpecialistTechnology::query()->insert($rows);
            $after = $this->technologies($employee);
            $normal = static fn (array $rows): array => collect($rows)
                ->map(static fn ($item) => implode(':', [
                    $item['id'], $item['kind'], $item['grade'] ?? '',
                ]))->sort()->values()->all();
            if ($normal($before) !== $normal($after) || $gradeBefore !== $profile->grade) {
                $format = static fn (array $rows): string => implode(', ', array_map(
                    static fn ($item) => $item['name'].($item['kind'] === 'primary'
                        ? ' ('.$item['grade'].')' : ' (вспом.)'), $rows));
                $detail = 'Было: '.($format($before) ?: 'не заполнено')
                    .'. Стало: '.($format($after) ?: 'не заполнено').'.';
                if ($gradeBefore !== $profile->grade) {
                    $detail .= ' Общий грейд: '.($gradeBefore ?: 'не указан')
                        .' → '.($profile->grade ?: 'не указан').'.';
                }
                $this->append($employee, 'professional', 'Изменение профессионального профиля', $detail, $actor, $author);
            }
            return ['grade' => $profile->grade, 'technologies' => $after];
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
