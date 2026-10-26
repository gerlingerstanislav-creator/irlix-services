<?php

namespace App\Services;

use App\Models\Partner;
use App\Models\PartnerSpecialist;
use Illuminate\Support\Facades\DB;

final class PartnersService
{
    /** Preserve exact registry/selector projections and sort order. */
    public function catalog(): array
    {
        return PartnerSpecialist::query()->from('partner_specialists as s')
            ->join('partners as p', 'p.id', '=', 's.partner_id')
            ->where('s.active', true)->where('p.active', true)
            ->orderBy('p.name')->orderBy('s.full_name')
            ->get(['s.id', 's.full_name', 's.partner_id', 'p.name as partner_name'])
            ->map(static fn ($item) => (object) $item->getRawOriginal())->all();
    }

    public function registry(): array
    {
        $partners = Partner::query()->orderBy('name')->get()
            ->map(static fn ($item) => (object) $item->getRawOriginal());
        $specialists = PartnerSpecialist::query()->orderBy('full_name')->get()
            ->map(static fn ($item) => (object) $item->getRawOriginal());
        $groups = $partners->map(static fn ($partner) => [
            ...(array) $partner,
            'specialists' => $specialists->where('partner_id', $partner->id)->values()->all(),
        ]);
        return [
            'partners' => $groups,
            'unassigned' => $specialists->whereNull('partner_id')->values()->all(),
        ];
    }

    public function partnerExists(int $id): bool
    {
        return Partner::query()->whereKey($id)->exists();
    }

    public function specialistExists(int $id): bool
    {
        return PartnerSpecialist::query()->whereKey($id)->exists();
    }

    public function createPartner(array $data, ?string $actor = null): object
    {
        return DB::transaction(function () use ($data, $actor) {
            $id = Partner::query()->insertGetId([...$data, 'created_at' => now(), 'updated_at' => now()]);
            app(PartnerProfileService::class)->record((int) $id, 'partner_created',
                'Создание партнёра', 'Создан новый партнёр', $actor);
            return (object) Partner::query()->findOrFail($id)->getRawOriginal();
        });
    }

    public function updatePartner(int $id, array $data, ?string $actor = null): object
    {
        return DB::transaction(function () use ($id, $data, $actor) {
            $existing = Partner::query()->whereKey($id)->lockForUpdate()->firstOrFail();
            $changes = array_filter(array_keys($data), static fn ($key) =>
                (string) ($existing->$key ?? '') !== (string) ($data[$key] ?? ''));
            if ($changes) {
                $existing->update($data);
                app(PartnerProfileService::class)->record($id, 'partner_updated',
                    'Изменение партнёра', 'Обновлены поля: '.implode(', ', $changes), $actor);
            }
            return (object) Partner::query()->findOrFail($id)->getRawOriginal();
        });
    }

    public function createSpecialist(array $data): object
    {
        $id = PartnerSpecialist::query()->insertGetId([...$data, 'created_at' => now(), 'updated_at' => now()]);
        return (object) (PartnerSpecialist::query()->findOrFail($id)->getRawOriginal());
    }

    public function updateSpecialist(int $id, array $data): object
    {
        PartnerSpecialist::query()->whereKey($id)->update([...$data, 'updated_at' => now()]);
        return (object) (PartnerSpecialist::query()->findOrFail($id)->getRawOriginal());
    }
}
