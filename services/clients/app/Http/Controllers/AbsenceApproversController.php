<?php

namespace App\Http\Controllers;

use App\Services\AbsenceApproversService;
use Illuminate\Http\Request;

final class AbsenceApproversController extends Controller
{
    public function __construct(private readonly AbsenceApproversService $approvers) {}

    public function __invoke(Request $request)
    {
        $data = $request->validate([
            'employee_id' => ['required', 'integer', 'min:1'],
            'from' => ['required', 'date'],
            'to' => ['required', 'date', 'after_or_equal:from'],
        ]);

        return response()->json(['data' => $this->approvers->forEmployee(
            (int) $data['employee_id'], $data['from'], $data['to']
        )]);
    }
}
