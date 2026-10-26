<?php

namespace App\Services;

use App\Models\Partner;
use App\Models\PartnerSpecialist;

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

    public function createPartner(array $data): object
    {
        $id = Partner::query()->insertGetId([...$data, 'created_at' => now(), 'updated_at' => now()]);
        return (object) (Partner::query()->findOrFail($id)->getRawOriginal());
    }

    public function updatePartner(int $id, array $data): object
    {
        Partner::query()->whereKey($id)->update([...$data, 'updated_at' => now()]);
        return (object) (Partner::query()->findOrFail($id)->getRawOriginal());
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
