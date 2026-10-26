<?php

namespace App\Http\Controllers;

use App\Services\TimesheetMembershipDirectory;
use Illuminate\Http\Request;

final class TimesheetMembershipsController extends Controller
{
    public function __construct(private readonly TimesheetMembershipDirectory $directory) {}

    public function __invoke(Request $request)
    {
        $expected = (string) env('IRLIX_TIMESHEETS_INTEGRATION_TOKEN', '');
        $given = (string) $request->header('X-Irlix-Timesheets-Token', '');
        abort_unless(strlen($expected) >= 32 && $given !== ''
            && hash_equals($expected, $given), 403, 'Timesheets integration forbidden');

        return response()->json($this->directory->snapshot());
    }
}
