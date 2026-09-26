<?php

use App\Support\EmployeesDirectory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

$directoryFor = function (Request $request): array {
    try { return app(EmployeesDirectory::class)->resolve($request); }
    catch (\DomainException $e) { abort(403, $e->getMessage()); }
    catch (\RuntimeException $e) { abort(503, $e->getMessage()); }
};
$visibleEmployee = function (array $directory, int $employeeId): ?array {
    foreach ($directory['employees'] ?? [] as $employee) if ((int)($employee['id'] ?? 0) === $employeeId) return $employee;
    return null;
};
$syncSnapshots = function (array $employees): void {
    foreach ($employees as $employee) {
        $employeeId = (int)($employee['id'] ?? 0); if (!$employeeId) continue;
        $values = ['last_department_id'=>$employee['department_id'] ?? null,'employment_status'=>$employee['employment_status'] ?? null,'updated_at'=>now()];
        if (DB::table('specialist_profiles')->where('employee_id',$employeeId)->exists()) DB::table('specialist_profiles')->where('employee_id',$employeeId)->update($values);
        else DB::table('specialist_profiles')->insert(['employee_id'=>$employeeId,...$values,'created_at'=>now()]);
    }
};
$profilePayload = function (array $employee): array {
    $employeeId = (int)$employee['id'];
    $profile = DB::table('specialist_profiles')->where('employee_id',$employeeId)->first();
    $technologies = DB::table('specialist_technologies as st')->join('technologies as t','t.id','=','st.technology_id')->where('st.employee_id',$employeeId)->select(['t.id','t.name','t.category'])->orderBy('t.name')->get();
    $competencies = DB::table('specialist_competencies as sc')->join('competencies as c','c.id','=','sc.competency_id')->leftJoin('technologies as t','t.id','=','c.technology_id')->where('sc.employee_id',$employeeId)->select(['c.id','c.name','c.description','c.technology_id','t.name as technology_name','sc.level','sc.comment'])->orderBy('c.name')->get();
    return ['employee'=>$employee,'professional'=>['grade'=>$profile?->grade,'manager_note'=>$profile?->manager_note,'technologies'=>$technologies,'competencies'=>$competencies]];
};

Route::get('/health', fn () => response()->json(['service'=>'specialists','status'=>'ok','database'=>DB::select('select 1') ? 'ok' : 'error']));

Route::get('/workspace', function (Request $request) use ($directoryFor,$syncSnapshots) {
    $directory=$directoryFor($request); $employees=$directory['employees'] ?? []; $syncSnapshots($employees);
    $ids=collect($employees)->pluck('id')->map(fn($id)=>(int)$id)->all();
    $profiles=DB::table('specialist_profiles')->whereIn('employee_id',$ids)->get()->keyBy('employee_id');
    $techMap=DB::table('specialist_technologies as st')->join('technologies as t','t.id','=','st.technology_id')->whereIn('st.employee_id',$ids)->select(['st.employee_id','t.id','t.name'])->orderBy('t.name')->get()->groupBy('employee_id');
    $people=collect($employees)->map(function($employee)use($profiles,$techMap){$id=(int)$employee['id'];$profile=$profiles->get($id);return [...$employee,'grade'=>$profile?->grade,'manager_note'=>$profile?->manager_note,'technologies'=>($techMap->get($id)??collect())->values()];})->values();
    return response()->json(['data'=>['actor'=>$directory['actor']??null,'scope'=>$directory['scope']??null,'managed_department_ids'=>$directory['managed_department_ids']??[],'departments'=>$directory['departments']??[],'people'=>$people,'catalog'=>['technologies'=>DB::table('technologies')->where('active',true)->orderBy('name')->get(),'competencies'=>DB::table('competencies as c')->leftJoin('technologies as t','t.id','=','c.technology_id')->where('c.active',true)->select(['c.id','c.name','c.description','c.technology_id','t.name as technology_name'])->orderBy('c.name')->get()]]]);
});

Route::get('/people/{employee}', function(Request $request,int $employee)use($directoryFor,$visibleEmployee,$syncSnapshots,$profilePayload){$directory=$directoryFor($request);$target=$visibleEmployee($directory,$employee);if(!$target)return response()->json(['message'=>'Specialist not found in your scope'],404);$syncSnapshots([$target]);return response()->json(['data'=>$profilePayload($target)]);})->whereNumber('employee');

Route::put('/people/{employee}', function(Request $request,int $employee)use($directoryFor,$visibleEmployee,$syncSnapshots,$profilePayload){
    $directory=$directoryFor($request);$target=$visibleEmployee($directory,$employee);if(!$target)return response()->json(['message'=>'Specialist not found in your scope'],404);
    $validated=$request->validate(['grade'=>['nullable','string','max:100'],'manager_note'=>['nullable','string','max:5000'],'technology_ids'=>['sometimes','array'],'technology_ids.*'=>['integer','exists:technologies,id'],'competencies'=>['sometimes','array'],'competencies.*.id'=>['required_with:competencies','integer','exists:competencies,id'],'competencies.*.level'=>['nullable','integer','min:0','max:100'],'competencies.*.comment'=>['nullable','string','max:2000']]);
    $syncSnapshots([$target]);
    DB::transaction(function()use($employee,$validated){$update=['updated_at'=>now()];if(array_key_exists('grade',$validated))$update['grade']=$validated['grade'];if(array_key_exists('manager_note',$validated))$update['manager_note']=$validated['manager_note'];DB::table('specialist_profiles')->where('employee_id',$employee)->update($update);if(array_key_exists('technology_ids',$validated)){DB::table('specialist_technologies')->where('employee_id',$employee)->delete();foreach(array_values(array_unique(array_map('intval',$validated['technology_ids'])))as$id)DB::table('specialist_technologies')->insert(['employee_id'=>$employee,'technology_id'=>$id,'created_at'=>now(),'updated_at'=>now()]);}if(array_key_exists('competencies',$validated)){DB::table('specialist_competencies')->where('employee_id',$employee)->delete();foreach($validated['competencies']as$c)DB::table('specialist_competencies')->insert(['employee_id'=>$employee,'competency_id'=>(int)$c['id'],'level'=>$c['level']??null,'comment'=>$c['comment']??null,'created_at'=>now(),'updated_at'=>now()]);}});
    return response()->json(['data'=>$profilePayload($target)]);
})->whereNumber('employee');

Route::get('/catalog', function(Request $request)use($directoryFor){$directoryFor($request);return response()->json(['data'=>['technologies'=>DB::table('technologies')->where('active',true)->orderBy('name')->get(),'competencies'=>DB::table('competencies as c')->leftJoin('technologies as t','t.id','=','c.technology_id')->where('c.active',true)->select(['c.id','c.name','c.description','c.technology_id','t.name as technology_name'])->orderBy('c.name')->get()]]);});
