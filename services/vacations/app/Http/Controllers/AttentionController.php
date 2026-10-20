<?php
namespace App\Http\Controllers;

use App\Support\CurrentEmployee;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use DomainException;
use RuntimeException;

/**
 * Domain-owned attention feed: only the current employee's absences and already
 * authorization-filtered approval tasks. No cross-employee raw query escapes this API.
 */
final class AttentionController extends Controller
{
    public function __construct(
        private readonly CurrentEmployee $currentEmployee,
        private readonly ApprovalController $approvals,
    ) {}

    public function index(Request $request): JsonResponse
    {
        try {
            $employee = $this->currentEmployee->resolve($request);
            $today = now()->startOfDay();
            $limit = $today->copy()->addDays(45);
            $items = [];
            $own = DB::table('absences')
                ->where('employee_id', (int) $employee['id'])
                ->whereDate('starts_on', '>=', $today->toDateString())
                ->whereDate('starts_on', '<=', $limit->toDateString())
                ->whereNotIn('status', ['confirmed', 'rejected', 'cancelled'])
                ->orderBy('starts_on')->limit(100)->get();
            foreach ($own as $absence) {
                $planned = $absence->status === 'planned';
                $items[] = [
                    'id' => 'absence:'.$absence->id,
                    'title' => $planned ? 'Отправить отпуск или отсутствие на согласование' : 'Проверить согласование отсутствия',
                    'detail' => $planned ? 'До начала отсутствия осталось менее 45 дней' : 'Отсутствие ещё не подтверждено',
                    'kind' => $planned ? 'attention' : 'upcoming',
                    'due_at' => substr((string) $absence->starts_on, 0, 10),
                    'href' => '/vacations/?absence='.$absence->id,
                ];
            }

            // Reuse the same permission/scope and delegate logic as the Vacations UI.
            $response = $this->approvals->index($request);
            if ($response->getStatusCode() !== 200) return $response;
            foreach (($response->getData(true)['data'] ?? []) as $approval) {
                $items[] = [
                    'id' => 'approval:'.$approval['id'],
                    'title' => 'Согласовать отсутствие'.(!empty($approval['employee_name']) ? ': '.$approval['employee_name'] : ''),
                    'detail' => 'Ожидает вашего решения',
                    'kind' => 'attention',
                    'due_at' => substr((string) $approval['starts_on'], 0, 10),
                    'href' => '/vacations/?absence='.$approval['absence_id'],
                ];
            }
            return response()->json(['data' => $items])->header('Cache-Control', 'no-store');
        } catch (DomainException $e) {
            return response()->json(['message'=>$e->getMessage()], 403);
        } catch (RuntimeException $e) {
            return response()->json(['message'=>$e->getMessage()], 503);
        }
    }
}
