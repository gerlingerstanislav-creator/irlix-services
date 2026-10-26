<?php

namespace App\Services;

use App\Models\Client;
use App\Models\ClientMember;
use App\Models\MemberTerm;
use App\Models\Project;
use App\Models\ProjectAssignment;
use App\Models\Partner;
use App\Models\PartnerSpecialist;
use App\Models\ClientMemberFeedback;
use App\Models\LegacyProjectMember;
use App\Models\ConnectionAttempt;
use App\Support\MemberIdentity;
use Illuminate\Support\Facades\DB;

final class ClientMembershipService
{
    private static function raw($model): ?object
    {
        return $model ? (object) $model->getRawOriginal() : null;
    }

    public function client(int $id): ?object
    {
        return self::raw(Client::query()->find($id));
    }

    public function member(int $id): object
    {
        $row = self::raw(ClientMember::query()->find($id));
        abort_unless($row, 404, 'Подключение не найдено.');
        return $row;
    }

    public function term(int $id): ?object
    {
        return self::raw(MemberTerm::query()->find($id));
    }

    public function assignment(int $id): ?object
    {
        return self::raw(ProjectAssignment::query()->find($id));
    }

    public function payload(object $member): array
    {
        $id = (int) $member->id;
        return [
            ...(array) $member,
            'terms' => MemberTerm::query()->where('client_member_id', $id)
                ->orderByDesc('valid_from')->get()
                ->map(static fn ($row) => (object) $row->getRawOriginal()),
            'projects' => ProjectAssignment::query()->from('project_assignments as a')
                ->join('projects as p', 'p.id', '=', 'a.project_id')
                ->where('a.client_member_id', $id)
                ->select('a.*', 'p.name as project_name')->orderByDesc('a.valid_from')->get()
                ->map(static fn ($row) => (object) $row->getRawOriginal()),
            'feedbacks' => ClientMemberFeedback::query()->where('client_member_id', $id)
                ->orderByDesc('created_at')->get()
                ->map(static fn ($row) => (object) $row->getRawOriginal()),
        ];
    }

    public function listForClient(int $clientId): array
    {
        return ClientMember::query()->where('client_id', $clientId)->orderBy('specialist_name')
            ->get()->map(fn ($model) => $this->payload((object) $model->getRawOriginal()))->all();
    }

    public function create(int $clientId, array $data): int
    {
        abort_if((!empty($data['employee_id'])) === (!empty($data['partner_specialist_id'])),
            422, 'Выберите ровно один тип специалиста.');
        $partner = !empty($data['partner_specialist_id'])
            ? PartnerSpecialist::query()->whereKey($data['partner_specialist_id'])->where('active', true)->first()
            : null;
        if (!empty($data['partner_specialist_id'])) {
            abort_unless($partner && $partner->partner_id
                && Partner::query()->whereKey($partner->partner_id)->where('active', true)->exists(),
                422, 'Специалист должен быть активным и принадлежать партнёру.');
        }
        $name = trim((string) ($partner?->full_name ?? ($data['specialist_name'] ?? '')));
        abort_if($name === '', 422, 'Требуется ФИО специалиста.');
        $where = [
            'client_id' => $clientId,
            $partner ? 'partner_specialist_id' : 'employee_id' => $partner?->id ?? $data['employee_id'],
        ];

        return DB::transaction(static function () use ($where, $clientId, $partner, $data, $name): int {
            Client::query()->whereKey($clientId)->lockForUpdate()->first();
            $existing = ClientMember::query()->where($where)->lockForUpdate()->first();
            $id = $existing?->id ?? ClientMember::query()->insertGetId([
                'client_id' => $clientId,
                'employee_id' => $partner ? null : $data['employee_id'],
                'partner_specialist_id' => $partner?->id,
                'specialist_name' => $name,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            if (!empty($data['source_attempt_id'])) {
                $attempt = ConnectionAttempt::query()->from('connection_attempts as a')
                    ->join('positions as p', 'p.id', '=', 'a.position_id')
                    ->join('client_requests as r', 'r.id', '=', 'p.client_request_id')
                    ->where('a.id', $data['source_attempt_id'])
                    ->select('a.*', 'r.client_id as attempt_client_id')
                    ->lockForUpdate()->first();
                abort_unless($attempt && (int) $attempt->attempt_client_id === $clientId,
                    422, 'Попытка относится к другому клиенту.');
                abort_unless((bool) $attempt->is_external === ($partner !== null)
                    && ($partner || (int) $attempt->specialist_id === (int) $data['employee_id']),
                    422, 'Личность не соответствует попытке.');
                abort_if($attempt->client_member_id && (int) $attempt->client_member_id !== (int) $id,
                    422, 'Попытка уже связана с другим подключением.');
                abort_unless(in_array($attempt->status, ['Ожидает подключения', 'Закрыт: успех'], true),
                    422, 'Попытка не готова к подключению.');
                ConnectionAttempt::query()->whereKey($attempt->id)->update([
                    'client_member_id' => $id, 'updated_at' => now(),
                ]);
            }
            return (int) $id;
        });
    }

    public function addTerm(object $member, array $data): object
    {
        $id = DB::transaction(function () use ($member, $data): int {
            $this->lockMembership($member);
            MemberIdentity::assertPeriodEditable((int) $member->client_id,
                $data['valid_from'], $data['valid_to'] ?? null);
            $overlap = MemberTerm::query()->where('client_member_id', $member->id)
                ->whereDate('valid_from', '<=', $data['valid_to'] ?? '9999-12-31')
                ->where(static fn ($q) => $q->whereNull('valid_to')
                    ->orWhereDate('valid_to', '>=', $data['valid_from']))->exists();
            abort_if($overlap, 422, 'Пересечение периодов коммерческих условий.');
            return MemberTerm::query()->insertGetId([
                ...$data, 'client_member_id' => $member->id,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        });
        return $this->term($id);
    }

    public function addProjectAssignment(object $member, array $data): object
    {
        abort_unless(Project::query()->whereKey($data['project_id'])
            ->where('client_id', $member->client_id)->exists(),
            422, 'Проект принадлежит другому клиенту.');
        $id = DB::transaction(function () use ($member, $data): int {
            $this->lockMembership($member);
            $overlap = ProjectAssignment::query()
                ->where('client_member_id', $member->id)->where('project_id', $data['project_id'])
                ->whereDate('valid_from', '<=', $data['valid_to'] ?? '9999-12-31')
                ->where(static fn ($q) => $q->whereNull('valid_to')
                    ->orWhereDate('valid_to', '>=', $data['valid_from']))->exists();
            abort_if($overlap, 422, 'Участие в этом проекте уже существует на указанный период.');
            return ProjectAssignment::query()->insertGetId([
                ...$data, 'client_member_id' => $member->id,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        });
        return $this->assignment($id);
    }

    public function addFeedback(object $member, array $data, ?string $username): object
    {
        if (!empty($data['project_id'])) {
            abort_unless(Project::query()->whereKey($data['project_id'])
                ->where('client_id', $member->client_id)->exists(),
                422, 'Проект не принадлежит клиенту.');
            $dated = ProjectAssignment::query()->where('client_member_id', $member->id)
                ->where('project_id', $data['project_id'])->whereDate('valid_from', '<=', now()->toDateString())
                ->exists();
            $historical = LegacyProjectMember::query()->where('project_id', $data['project_id'])
                ->where($member->partner_specialist_id !== null ? 'partner_specialist_id' : 'specialist_id',
                    $member->partner_specialist_id ?? $member->employee_id)->exists();
            abort_unless($dated || $historical, 422, 'Специалист никогда не участвовал в этом проекте.');
        }
        abort_if(trim($data['text']) === '', 422, 'Текст обратной связи обязателен.');
        $id = ClientMemberFeedback::query()->insertGetId([
            'client_member_id' => $member->id,
            'project_id' => $data['project_id'] ?? null,
            'text' => trim($data['text']), 'created_by_username' => $username,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        return self::raw(ClientMemberFeedback::query()->find($id));
    }

    public function updateTerm(object $member, object $previous, int $termId, array $data): object
    {
        $from = $data['valid_from'] ?? substr((string) $previous->valid_from, 0, 10);
        $to = array_key_exists('valid_to', $data) ? $data['valid_to'] : $previous->valid_to;
        abort_if($to !== null && $to < $from, 422, 'Дата окончания раньше даты начала.');

        return DB::transaction(function () use ($member, $termId, $data): object {
            $this->lockMembership($member);
            $row = MemberTerm::query()->whereKey($termId)->lockForUpdate()->first();
            abort_unless($row && (int) $row->client_member_id === (int) $member->id,
                409, 'Условия подключения изменились.');
            $raw = (object) $row->getRawOriginal();
            $from = $data['valid_from'] ?? substr((string) $raw->valid_from, 0, 10);
            $to = array_key_exists('valid_to', $data) ? $data['valid_to'] : $raw->valid_to;
            abort_if($to !== null && $to < $from, 422, 'Дата окончания раньше даты начала.');
            MemberIdentity::assertTermChangeEditable((int) $member->client_id, $raw, $data, $from, $to);
            $overlap = MemberTerm::query()->where('client_member_id', $member->id)
                ->where('id', '<>', $termId)
                ->whereDate('valid_from', '<=', $to ?? '9999-12-31')
                ->where(static fn ($q) => $q->whereNull('valid_to')->orWhereDate('valid_to', '>=', $from))
                ->exists();
            abort_if($overlap, 422, 'Условия пересекаются с другой версией.');
            MemberTerm::query()->whereKey($termId)->update([...$data, 'updated_at' => now()]);
            return $this->term($termId);
        });
    }

    public function updateProjectAssignment(object $member, object $previous, int $assignmentId, array $data): object
    {
        $projectId = $data['project_id'] ?? $previous->project_id;
        $from = $data['valid_from'] ?? substr((string) $previous->valid_from, 0, 10);
        $to = array_key_exists('valid_to', $data) ? $data['valid_to'] : $previous->valid_to;
        abort_if($to !== null && $to < $from, 422, 'Дата окончания раньше начала.');
        abort_unless(Project::query()->whereKey($projectId)
            ->where('client_id', $member->client_id)->exists(), 422, 'Проект принадлежит другому клиенту.');

        return DB::transaction(function () use ($member, $assignmentId, $projectId, $from, $to, $data): object {
            $this->lockMembership($member);
            $overlap = ProjectAssignment::query()->where('client_member_id', $member->id)
                ->where('id', '<>', $assignmentId)->where('project_id', $projectId)
                ->whereDate('valid_from', '<=', $to ?? '9999-12-31')
                ->where(static fn ($q) => $q->whereNull('valid_to')->orWhereDate('valid_to', '>=', $from))
                ->exists();
            abort_if($overlap, 422, 'Пересечение назначений на один проект.');
            ProjectAssignment::query()->whereKey($assignmentId)->update([...$data, 'updated_at' => now()]);
            return $this->assignment($assignmentId);
        });
    }

    private function lockMembership(object $member): void
    {
        Client::query()->whereKey($member->client_id)->lockForUpdate()->first();
        ClientMember::query()->whereKey($member->id)->lockForUpdate()->first();
    }
}
