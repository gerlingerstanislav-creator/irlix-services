<?php

namespace App\Services;

use App\Models\Client;
use App\Models\ClientMember;
use App\Models\MemberTerm;

final class ClientSpecialistsService
{
    /** Lightweight catalog only; do not expose client terms or rates. */
    public function clients(): array
    {
        return Client::query()->select(['id', 'name'])->orderBy('name')->get()
            ->map(static fn ($item) => $item->getRawOriginal())->all();
    }

    /** Return only employee identity and ClientMember term dates for filter matching. */
    public function members(int $clientId): array
    {
        abort_unless(Client::query()->whereKey($clientId)->exists(), 404);
        $members = ClientMember::query()->where('client_id', $clientId)
            ->whereNotNull('employee_id')->select(['id', 'employee_id'])->get();
        if ($members->isEmpty()) return [];
        $terms = MemberTerm::query()->whereIn('client_member_id', $members->pluck('id')->all())
            ->select(['client_member_id', 'valid_from', 'valid_to'])
            ->orderBy('valid_from')->get()->groupBy('client_member_id');
        return $members->map(static fn ($row) => [
            'employee_id' => (int) $row->employee_id,
            'terms' => ($terms[$row->id] ?? collect())->map(static fn ($term) => [
                'valid_from' => $term->valid_from, 'valid_to' => $term->valid_to,
            ])->all(),
        ])->all();
    }
}
