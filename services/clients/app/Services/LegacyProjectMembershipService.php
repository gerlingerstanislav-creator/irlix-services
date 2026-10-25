<?php

namespace App\Services;

use App\Models\Client;
use App\Models\Project;
use App\Models\LegacyProjectMember;
use App\Models\MemberTerm;
use App\Models\ConnectionAttempt;
use App\Models\Position;
use App\Models\ClientRequest;
use App\Models\PartnerSpecialist;
use App\Models\Partner;
use App\Models\ClientMember;
use App\Models\ProjectAssignment;
use App\Support\MemberIdentity;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Compatibility writer for the old project-based connection endpoint.
 * Persist the canonical ClientMember/MemberTerm and preserve the old member id.
 */
final class LegacyProjectMembershipService
{
    public function project(int $id): ?object
    {
        $model = Project::query()->find($id);
        return $model ? (object) $model->getRawOriginal() : null;
    }

    public function attach(int $project, object $projectRow, array $data, bool $external): int
    {
        return DB::transaction(function () use ($data, $external, $project, $projectRow) {
            Client::query()->where('id',$projectRow->client_id)->lockForUpdate()->first();
            \App\Support\MemberIdentity::assertPeriodEditable((int)$projectRow->client_id,$data['valid_from'],$data['valid_to']??null);
            $attempt = !empty($data['source_attempt_id']) ? ConnectionAttempt::query()->where('id', $data['source_attempt_id'])->lockForUpdate()->first() : null;
            if (!empty($data['source_attempt_id'])) {
                abort_unless($attempt, 422, 'Попытка подключения не найдена.');
                abort_unless((bool) $attempt->is_external === $external && ($external || (int) $attempt->specialist_id === (int) $data['specialist_id']), 422, 'Специалист не соответствует попытке подключения.');
                $position = Position::query()->find($attempt->position_id);
                $clientRequest = $position ? ClientRequest::query()->find($position->client_request_id) : null;
                abort_unless($clientRequest && (int) $clientRequest->client_id === (int) $projectRow->client_id, 422, 'Попытка относится к другому клиенту.');
            }
            $member = $external ? ($attempt ? LegacyProjectMember::query()->where('source_attempt_id', $attempt->id)->first() : null)
                : LegacyProjectMember::query()->where(['project_id' => $project, 'specialist_id' => $data['specialist_id']])->first();

            $catalogPartner = $external && !empty($data['partner_specialist_id'])
                ? PartnerSpecialist::query()->where('id', $data['partner_specialist_id'])->where('active', true)->first() : null;
            if ($external && !empty($data['partner_specialist_id']))
                abort_unless($catalogPartner && $catalogPartner->partner_id && Partner::query()->where('id',$catalogPartner->partner_id)->where('active',true)->exists(), 422, 'Партнёрский специалист не найден или неактивен.');
            if($external) abort_unless($catalogPartner || $member,422,'Выберите зарегистрированного партнёрского специалиста.');
            $partnerId=$external?($catalogPartner?->id??$member?->partner_specialist_id):null;
            if ($external && $member && $catalogPartner)
                abort_unless((int) $member->partner_specialist_id === (int) $catalogPartner->id, 422, 'Попытка привязана к другой личности.');
            if ($external && !$member)
                $member = LegacyProjectMember::query()->where(['project_id' => $project, 'partner_specialist_id' => $partnerId])->first();
            $employeeId = $external ? null : (int) $data['specialist_id'];
            if (!$member) {
                $memberId = LegacyProjectMember::query()->insertGetId([
                    'project_id' => $project,
                    'specialist_id' => $employeeId,
                    'partner_specialist_id' => $partnerId,
                    'specialist_name' => $catalogPartner?->full_name ?? ($external && $attempt ? $attempt->specialist_name : $data['specialist_name']),
                    'source_attempt_id' => $data['source_attempt_id'] ?? null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            } else {
                $memberId = $member->id;
            }

            $this->assertConditionsAvailable(
                (int) $projectRow->client_id,
                $employeeId ?? 0,
                $data['valid_from'],
                $data['valid_to'] ?? null,
                null, $partnerId,
            );

            // Keep the legacy ProjectMember as an adapter, but the enduring
            // client membership is canonical and independent of the project.
            $identity=['client_id'=>(int)$projectRow->client_id,
                $partnerId!==null?'partner_specialist_id':'employee_id'=>$partnerId??$employeeId];
            $clientMember=ClientMember::query()->where($identity)->lockForUpdate()->first();
            $clientMemberId=$clientMember?->id??ClientMember::query()->insertGetId([
                'client_id'=>(int)$projectRow->client_id,'employee_id'=>$employeeId,
                'partner_specialist_id'=>$partnerId,
                'specialist_name'=>$catalogPartner?->full_name??$data['specialist_name'],
                'created_at'=>now(),'updated_at'=>now(),
            ]);
            if (Schema::hasTable('project_assignments')) {
                $exists=ProjectAssignment::query()->where('client_member_id',$clientMemberId)->where('project_id',$project)
                    ->whereDate('valid_from','<=',$data['valid_to']??'9999-12-31')
                    ->where(fn($q)=>$q->whereNull('valid_to')->orWhereDate('valid_to','>=',$data['valid_from']))->exists();
                if (!$exists) ProjectAssignment::query()->insert(['client_member_id'=>$clientMemberId,'project_id'=>$project,
                    'valid_from'=>$data['valid_from'],'valid_to'=>$data['valid_to']??null,'created_at'=>now(),'updated_at'=>now()]);
            }
            MemberTerm::query()->insert([
                'client_member_id'=>$clientMemberId,
                'project_member_id' => $memberId,
                'technology' => $data['technology'],
                'level' => $data['level'],
                'hourly_rate' => $data['hourly_rate'],
                'hours_per_day' => $data['hours_per_day'],
                'valid_from' => $data['valid_from'],
                'valid_to' => $data['valid_to'] ?? null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            if (!empty($data['source_attempt_id'])) {
                ConnectionAttempt::query()->where('id', $data['source_attempt_id'])->update([
                    'client_member_id'=>$clientMemberId,
                    'status' => 'Закрыт: успех',
                    'closed_at' => now(),
                    'updated_at' => now(),
                ]);
            }
            return $memberId;
        });
    }

    private function assertConditionsAvailable(
        int $clientId, int $specialistId, string $from, ?string $to,
        ?int $excludeTermId = null, ?int $partnerId = null
    ): void {
        MemberIdentity::assertConditionsAvailable($clientId, $specialistId ?: null,
            $partnerId, $from, $to, $excludeTermId);
    }
}
