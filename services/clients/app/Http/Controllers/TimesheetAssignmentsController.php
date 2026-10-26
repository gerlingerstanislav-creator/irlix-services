<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class TimesheetAssignmentsController extends Controller
{
    public function __invoke(Request $request)
    {
        $expected = (string) env('IRLIX_TIMESHEETS_INTEGRATION_TOKEN', '');
        $provided = (string) $request->header('X-Irlix-Timesheets-Token', '');
        abort_unless($expected !== '' && $provided !== '' && hash_equals($expected, $provided), 403);

        return response()->json(app(\App\Services\TimesheetAssignmentsService::class)->snapshot());
    }
}
