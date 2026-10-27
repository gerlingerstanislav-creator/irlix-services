<?php
// Purely synthetic replay of the one-way legacy lifecycle normalization.
require __DIR__.'/../vendor/autoload.php';
$container = new Illuminate\Container\Container();
Illuminate\Container\Container::setInstance($container);
Illuminate\Support\Facades\Facade::setFacadeApplication($container);
$capsule = new Illuminate\Database\Capsule\Manager($container);
$capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
$container->instance('db', $capsule->getDatabaseManager());
Illuminate\Database\Eloquent\Model::setConnectionResolver($capsule->getDatabaseManager());
use Illuminate\Support\Facades\DB;

function mustNormalize(bool $ok, string $reason): void
{
    if (!$ok) throw new RuntimeException($reason);
}

DB::statement('CREATE TABLE absences (id INTEGER PRIMARY KEY, status TEXT, created_by_subject TEXT, submitted_at TEXT)');
DB::statement('CREATE TABLE absence_approvals (id INTEGER PRIMARY KEY, absence_id INTEGER, sequence INTEGER, stage TEXT, status TEXT)');
DB::statement('CREATE TABLE absence_audit_log (id INTEGER PRIMARY KEY, absence_id INTEGER, event TEXT, "after" TEXT)');

foreach ([
    [1, 'hr_review', 'normal', 'submitted'],
    [2, 'planned', 'legacy-migration', null],
    [3, 'planned', 'legacy-migration', null],
    [4, 'planned', 'legacy-migration', null],
    [5, 'manager_review', 'normal', 'submitted'],
    [6, 'confirmed', 'legacy-migration', null],
] as [$id, $status, $actor, $submitted]) {
    DB::table('absences')->insert(['id'=>$id,'status'=>$status,'created_by_subject'=>$actor,'submitted_at'=>$submitted]);
}

foreach ([
    [1,1,1,'hr_review','pending'],
    [2,2,1,'employee_review','approved'],
    [3,2,2,'employee_review','waiting'],
    [4,3,1,'employee_review','waiting'],
    [5,4,1,'employee_review','approved'],
    [6,5,1,'manager_review','waiting'],
] as [$id,$absence,$sequence,$stage,$status]) {
    DB::table('absence_approvals')->insert(['id'=>$id,'absence_id'=>$absence,'sequence'=>$sequence,'stage'=>$stage,'status'=>$status]);
}
foreach ([2,3,4] as $id) {
    DB::table('absence_audit_log')->insert(['id'=>$id,'absence_id'=>$id,'event'=>'legacy_migrated',
        'after'=>json_encode(['status'=>'on_approval'])]);
}
DB::table('absence_audit_log')->insert(['id'=>8,'absence_id'=>3,'event'=>'updated','after'=>'{}']);
$normalizer = require __DIR__.'/../database/migrations/2026_10_27_000001_normalize_absence_review_lifecycle.php';
$normalizer->up();
mustNormalize(DB::table('absences')->find(1)->status==='in_review', 'Live active review not normalized');
mustNormalize(DB::table('absences')->find(2)->status==='in_review', 'Untouched legacy review not normalized');
mustNormalize(DB::table('absence_approvals')->find(3)->status==='pending', 'Unapproved historical group not activated');
mustNormalize(DB::table('absence_approvals')->find(2)->status==='approved', 'Prior approval decision overwritten');
mustNormalize(DB::table('absences')->find(3)->status==='planned', 'User-modified legacy row must not change');
mustNormalize(DB::table('absence_approvals')->find(4)->status==='waiting', 'User-modified approval must not change');
mustNormalize(DB::table('absences')->find(4)->status==='planned', 'Fully approved ambiguous old review must not be invented');
mustNormalize(DB::table('absences')->find(5)->status==='manager_review', 'Unmapped legacy stage must retain its evidence');
mustNormalize(DB::table('absences')->find(6)->status==='confirmed', 'Terminal state changed');
$normalizer->up();
mustNormalize(DB::table('absence_approvals')->where('status','pending')->count()===2,
    'Repeated normalization must not duplicate or reactivate approvals');
echo "Vacation lifecycle normalization smoke passed\n";
