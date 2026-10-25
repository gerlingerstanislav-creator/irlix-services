<?php

namespace App\Services;

use App\Models\Client;
use App\Models\ClientMember;
use App\Models\MemberTerm;
use App\Support\MemberIdentity;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class LegacyMemberTermsService
{
    /** Transaction retains period and rate-change consistency for the canonical member. */
    public function create(int $legacyProjectMemberId, array $data): object
    {
        $member = MemberIdentity::legacyMember($legacyProjectMemberId);
        return DB::transaction(static function () use ($member, $legacyProjectMemberId, $data): object {
            Client::query()->whereKey($member->client_id)->lockForUpdate()->first();
            ClientMember::query()->whereKey($member->id)->lockForUpdate()->first();

            MemberIdentity::assertPeriodEditable((int) $member->client_id,
                $data['valid_from'], $data['valid_to'] ?? null);
            $previous = MemberTerm::query()
                ->where('client_member_id', $member->id)
                ->whereDate('valid_from', '<', $data['valid_from'])
                ->where(static fn ($q) => $q->whereNull('valid_to')
                    ->orWhereDate('valid_to', '>=', $data['valid_from']))->first();

            if ($previous) {
                $newEnd = CarbonImmutable::parse($data['valid_from'])->subDay()->toDateString();
                MemberIdentity::assertTermChangeEditable((int) $member->client_id,
                    (object) $previous->getRawOriginal(), [], (string) $previous->valid_from, $newEnd);
                MemberTerm::query()->whereKey($previous->id)->update([
                    'valid_to' => $newEnd, 'updated_at' => now(),
                ]);
            }

            MemberIdentity::assertConditionsAvailable((int) $member->client_id,
                $member->employee_id, $member->partner_specialist_id,
                $data['valid_from'], $data['valid_to'] ?? null);

            $id = MemberTerm::query()->insertGetId([
                ...$data, 'client_member_id' => $member->id,
                'project_member_id' => $legacyProjectMemberId,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            return (object) MemberTerm::query()->findOrFail($id)->getRawOriginal();
        });
    }
}
