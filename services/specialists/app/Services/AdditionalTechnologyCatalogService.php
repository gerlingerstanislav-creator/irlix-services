<?php

namespace App\Services;

use App\Models\AdditionalTechnology;
use App\Models\SpecialistAdditionalTechnology;
use App\Models\SpecialistProfile;
use App\Models\SpecialistTechnology;
use App\Models\SpecialistTimelineEvent;
use Illuminate\Support\Facades\DB;

final class AdditionalTechnologyCatalogService
{
    /**
     * Rename the catalog identity in place so all assigned specialists retain
     * the same association ID. Serialize against profile saves and deletion,
     * and write per-specialist history in the same transaction.
     */
    public function rename(int $id, string $rawName, int $actor, string $author): array
    {
        $name = trim($rawName);
        abort_if($name === '' || mb_strlen($name) > 255, 422, 'Введите название технологии (до 255 символов).');

        return DB::transaction(function () use ($id, $name, $actor, $author): array {
            $technology = AdditionalTechnology::query()->whereKey($id)->lockForUpdate()->first();
            abort_unless($technology && $technology->active, 404, 'Дополнительная технология не найдена.');

            $original = (string) $technology->name;
            if ($original === $name) return ['id' => $id, 'name' => $name, 'affected_specialists' => 0, 'changed' => false];

            // Catalog identity is unique by normalized name, including retired
            // identities that are retained for migration and audit provenance.
            $normalized = mb_strtolower($name);
            abort_if(AdditionalTechnology::query()->where('id', '<>', $id)
                ->whereRaw('LOWER(TRIM(name)) = ?', [$normalized])->exists(),
                422, 'Дополнительная технология с таким названием уже существует.');

            $members = SpecialistAdditionalTechnology::query()
                ->where('additional_technology_id', $id)->orderBy('employee_id')
                ->pluck('employee_id')->map(static fn ($value): int => (int) $value)->all();

            if ($members) {
                SpecialistProfile::query()->whereIn('employee_id', $members)
                    ->orderBy('employee_id')->lockForUpdate()->get();

                // Renaming must not make an existing additional technology
                // duplicate a main skill name for any affected specialist.
                $main = SpecialistTechnology::query()->from('specialist_technologies as st')
                    ->join('technologies as t', 't.id', '=', 'st.technology_id')
                    ->whereIn('st.employee_id', $members)->where('st.kind', 'primary')
                    ->get(['st.employee_id', 't.name']);
                foreach ($main as $entry) {
                    abort_if(mb_strtolower(trim((string) $entry->name)) === $normalized,
                        409, 'Новое название совпадает с основной технологией одного из специалистов.');
                }
            }

            try {
                $technology->name = $name;
                $technology->save();
            } catch (\Illuminate\Database\QueryException $e) {
                // PostgreSQL's normalized-name unique index also guards
                // against parallel catalog writers between check and save.
                if (in_array((string) $e->getCode(), ['23505', '23000'], true)) {
                    abort(422, 'Дополнительная технология с таким названием уже существует.');
                }
                throw $e;
            }

            foreach ($members as $employee) {
                SpecialistTimelineEvent::query()->create([
                    'employee_id' => $employee,
                    'type' => 'professional',
                    'title' => 'Переименование дополнительной технологии',
                    'text' => 'Дополнительная технология «'.$original.'» переименована в «'.$name.'» (изменение общего каталога).',
                    'author_employee_id' => $actor > 0 ? $actor : null,
                    'author' => $author,
                ]);
            }

            return ['id' => $id, 'name' => $name,
                'affected_specialists' => count($members), 'changed' => true];
        }, 3);
    }

    /**
     * Retire a catalog entry for every specialist, including people outside the
     * current editor's organizational scope and former employees.
     *
     * Serialize with profile autosaves by locking catalog rows before profile
     * rows in both use cases. If replacement is already assigned, keep its
     * existing row; never create duplicate (employee,technology) keys.
     */
    public function removeOrReplace(int $sourceId, ?int $replacementId, int $actor, string $author): array
    {
        abort_if($replacementId === $sourceId, 422, 'Нельзя заменить технологию самой собой.');

        return DB::transaction(function () use ($sourceId, $replacementId, $actor, $author): array {
            $ids = array_values(array_unique(array_filter([$sourceId, $replacementId],
                static fn (?int $id): bool => $id !== null)));
            $locked = AdditionalTechnology::query()->whereIn('id', $ids)->orderBy('id')
                ->lockForUpdate()->get()->keyBy('id');
            $source = $locked->get($sourceId);
            abort_unless($source && $source->active, 404, 'Дополнительная технология не найдена.');

            $replacement = $replacementId === null ? null : $locked->get($replacementId);
            abort_if($replacementId !== null && (!$replacement || !$replacement->active), 422,
                'Выбранная технология для замены недоступна.');

            $members = SpecialistAdditionalTechnology::query()
                ->where('additional_technology_id', $sourceId)
                ->orderBy('employee_id')->pluck('employee_id')->map(static fn ($id): int => (int) $id)->all();

            if ($members) {
                // Align lock ordering with SpecialistProfessionalService::replace.
                SpecialistProfile::query()->whereIn('employee_id', $members)
                    ->orderBy('employee_id')->lockForUpdate()->get();

                if ($replacement) {
                    // Preserve the invariant that a normalized name cannot be
                    // both primary and additional for the same specialist.
                    $primaryNames = SpecialistTechnology::query()->from('specialist_technologies as st')
                        ->join('technologies as t', 't.id', '=', 'st.technology_id')
                        ->whereIn('st.employee_id', $members)->where('st.kind', 'primary')
                        ->get(['st.employee_id', 't.name']);
                    $replacementName = mb_strtolower(trim((string) $replacement->name));
                    foreach ($primaryNames as $primary) {
                        abort_if(mb_strtolower(trim((string) $primary->name)) === $replacementName,
                            409, 'У одного из специалистов замена уже указана как основная технология. Выберите другую.');
                    }

                    $existing = SpecialistAdditionalTechnology::query()
                        ->where('additional_technology_id', $replacementId)
                        ->whereIn('employee_id', $members)
                        ->pluck('employee_id')->map(static fn ($id): int => (int) $id)->all();
                    $newMembers = array_values(array_diff($members, $existing));
                    $time = now();
                    foreach (array_chunk($newMembers, 500) as $chunk) {
                        SpecialistAdditionalTechnology::query()->insertOrIgnore(
                            array_map(static fn (int $employee): array => [
                                'employee_id' => $employee,
                                'additional_technology_id' => $replacementId,
                                'created_at' => $time, 'updated_at' => $time,
                            ], $chunk)
                        );
                    }
                }

                SpecialistAdditionalTechnology::query()
                    ->where('additional_technology_id', $sourceId)->delete();

                $description = $replacement
                    ? 'Дополнительная технология «'.$source->name.'» заменена на «'.$replacement->name.'» (глобальное изменение каталога).'
                    : 'Дополнительная технология «'.$source->name.'» удалена из профиля (глобальное изменение каталога).';
                foreach ($members as $employee) {
                    SpecialistTimelineEvent::query()->create([
                        'employee_id' => $employee, 'type' => 'professional',
                        'title' => $replacement ? 'Замена дополнительной технологии' : 'Удаление дополнительной технологии',
                        'text' => $description,
                        'author_employee_id' => $actor > 0 ? $actor : null,
                        'author' => $author,
                    ]);
                }
            }

            // Archive, rather than physically destroy, the source catalog
            // identity for migration provenance and reproducible audit.
            $source->active = false;
            $source->save();

            return [
                'deleted' => true,
                'id' => $sourceId,
                'replacement_id' => $replacementId,
                'affected_specialists' => count($members),
            ];
        }, 3);
    }
}
