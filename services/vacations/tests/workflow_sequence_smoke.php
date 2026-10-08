<?php
// Real service + offline DB regression; identities and files are synthetic.
require __DIR__.'/../vendor/autoload.php';
$container = new Illuminate\Container\Container();
Illuminate\Container\Container::setInstance($container);
Illuminate\Support\Facades\Facade::setFacadeApplication($container);
$capsule = new Illuminate\Database\Capsule\Manager($container);
$capsule->addConnection(['driver'=>'sqlite','database'=>':memory:','prefix'=>'']);
$container->instance('db',$capsule->getDatabaseManager());
use Illuminate\Support\Facades\DB;
function checkSequence(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
DB::statement('CREATE TABLE absences (id INTEGER PRIMARY KEY,employee_id INTEGER,type TEXT,status TEXT,starts_on TEXT,ends_on TEXT,calendar_days INTEGER,entitlement_days INTEGER,comment TEXT,submitted_at TEXT,confirmed_at TEXT,created_at TEXT,updated_at TEXT)');
DB::statement('CREATE TABLE absence_approvals (id INTEGER PRIMARY KEY,absence_id INTEGER,sequence INTEGER,stage TEXT,status TEXT,required_role TEXT,approver_employee_id INTEGER,acted_by_subject TEXT,acted_at TEXT,updated_at TEXT)');
DB::statement('CREATE TABLE absence_status_history (id INTEGER PRIMARY KEY,absence_id INTEGER,from_status TEXT,to_status TEXT,actor_subject TEXT,actor_employee_id INTEGER,reason TEXT,context TEXT,created_at TEXT)');
DB::statement('CREATE TABLE absence_audit_log (id INTEGER PRIMARY KEY,absence_id INTEGER,event TEXT,actor_subject TEXT,actor_employee_id INTEGER,before TEXT,after TEXT,created_at TEXT)');
DB::table('absences')->insert(['id'=>1,'employee_id'=>10,'type'=>'paid_vacation','status'=>'planned','starts_on'=>'2026-01-10','ends_on'=>'2026-01-12']);
foreach ([[1,1,'approved'],[2,2,'waiting'],[3,2,'waiting'],[4,3,'waiting']] as [$id,$sequence,$status]) DB::table('absence_approvals')->insert(['id'=>$id,'absence_id'=>1,'sequence'=>$sequence,'stage'=>'employee_review','status'=>$status,'approver_employee_id'=>20+$id]);
$service = new App\Application\AbsenceService(new App\Domain\Absence\AbsenceDayCalculator(),new App\Domain\Absence\AbsenceWorkflow());
$service->submitOwn(1,10,'synthetic-owner',[]);
checkSequence(DB::table('absence_approvals')->where('status','pending')->count()===2,'Only first unresolved sequence becomes pending');
$service->approve(2,22,'synthetic-approver',fn()=>true);
checkSequence(DB::table('absence_approvals')->find(4)->status==='waiting','Parallel group must finish before next sequence');
$service->approve(3,23,'synthetic-approver',fn()=>true);
checkSequence(DB::table('absence_approvals')->find(4)->status==='pending','Repeated employee stage advances by sequence');
$service->approve(4,24,'synthetic-approver',fn()=>true);
checkSequence(DB::table('absences')->find(1)->status==='confirmed','Last sequence confirms absence');
DB::table('absences')->where('id',1)->update(['status'=>'employee_review']);
$service->returnToPlanned(1,99,'synthetic-admin');
checkSequence(DB::table('absence_approvals')->count()===4 && DB::table('absence_approvals')->where('status','waiting')->count()===4,'Return retains assigned chain and clears decisions');
$service->submitOwn(1,10,'synthetic-owner',[]);
checkSequence(DB::table('absence_approvals')->find(1)->status==='pending','Resubmission reuses assigned chain');
echo "Assigned approval chain regression passed\n";
