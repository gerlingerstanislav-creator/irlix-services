<?php

namespace App\Http\Controllers;

use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class MemberTermsController extends Controller
{
    public function store(Request $request, int $member)
    {
        $memberRow = DB::table('project_members')->find($member);
        abort_unless($memberRow, 404, 'Project member not found');

        $project = DB::table('projects')->find($memberRow->project_id);
        abort_unless($project, 404, 'Project not found');

        $data = $request->validate([
            'technology' => ['required', 'string', 'max:100'],
            'level' => ['required', 'string', 'max:100'],
            'hourly_rate' => ['required', 'numeric', 'min:0'],
            'hours_per_day' => ['required', 'numeric', 'min:0', 'max:24'],
            'valid_from' => ['required', 'date'],
            'valid_to' => ['nullable', 'date', 'after_or_equal:valid_from'],
        ]);

        $id = DB::transaction(function () use ($member, $memberRow, $project, $data) {
            $newFrom = CarbonImmutable::parse($data['valid_from'])->startOfDay();
            $previousEnd = $newFrom->subDay()->toDateString();

            $previous = DB::table('member_terms')
                ->where('project_member_id', $member)
                ->whereDate('valid_from', '<', $newFrom->toDateString())
                ->where(function ($query) use ($newFrom) {
                    $query->whereNull('valid_to')
                        ->orWhereDate('valid_to', '>=', $newFrom->toDateString());
                })
                ->orderByDesc('valid_from')
                ->orderByDesc('id')
                ->first();

            if ($previous) {
                DB::table('member_terms')->where('id', $previous->id)->update([
                    'valid_to' => $previousEnd,
                    'updated_at' => now(),
                ]);
            }

            $this->assertConditionsAvailable(
                (int) $project->client_id,
                (int) $memberRow->specialist_id,
                $data['valid_from'],
                $data['valid_to'] ?? null,
            );

            return DB::table('member_terms')->insertGetId([
                'project_member_id' => $member,
                ...$data,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        return response()->json(['data' => DB::table('member_terms')->find($id)], 201);
    }

    private function assertConditionsAvailable(int $clientId, int $specialistId, string $from, ?string $to): void
    {
        $end = $to ?? '9999-12-31';
        $exists = DB::table('member_terms as mt')
            ->join('project_members as pm', 'pm.id', '=', 'mt.project_member_id')
            ->join('projects as p', 'p.id', '=', 'pm.project_id')
            ->where('p.client_id', $clientId)
            ->where('pm.specialist_id', $specialistId)
            ->whereRaw("daterange(mt.valid_from, COALESCE(mt.valid_to, DATE '9999-12-31'), '[]') && daterange(?::date, ?::date, '[]')", [$from, $end])
            ->exists();

        abort_if($exists, 422, 'У специалиста уже есть пересекающиеся условия работы у этого клиента.');
    }
}
