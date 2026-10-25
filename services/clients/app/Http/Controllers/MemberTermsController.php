<?php

namespace App\Http\Controllers;

use App\Services\LegacyMemberTermsService;
use Illuminate\Http\Request;

final class MemberTermsController extends Controller
{
    public function __construct(private readonly LegacyMemberTermsService $terms) {}

    // Historical URLs still identify ProjectMember. Canonical terms belong to ClientMember.
    public function store(Request $request, int $member)
    {
        $data = $request->validate([
            'technology' => ['required', 'string', 'max:100'],
            'level' => ['required', 'string', 'max:100'],
            'hourly_rate' => ['required', 'numeric', 'min:0'],
            'hours_per_day' => ['required', 'numeric', 'min:0', 'max:24'],
            'valid_from' => ['required', 'date'],
            'valid_to' => ['nullable', 'date', 'after_or_equal:valid_from'],
        ]);
        return response()->json(['data' => $this->terms->create($member, $data)], 201);
    }
}
