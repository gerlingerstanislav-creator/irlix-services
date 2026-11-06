<?php

// Disposable SQLite fixture: linked employee accrual chains are removed without
// deleting another employee's accrual or a company's bank transaction.
require __DIR__.'/../vendor/autoload.php';

use App\Services\EmployeePurgeService;
use App\Support\EmployeePurgeRepository;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;

$app = new Container();
Container::setInstance($app);
Facade::setFacadeApplication($app);
$capsule = new Manager($app);
$capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
$db = $capsule->getConnection();
DB::swap($db);

$db->statement('CREATE TABLE accruals (id INTEGER PRIMARY KEY,employee_id INTEGER,reversal_of_accrual_id INTEGER REFERENCES accruals(id) ON DELETE RESTRICT,created_by TEXT)');
$db->statement('CREATE TABLE accrual_payment_links (id INTEGER PRIMARY KEY,accrual_id INTEGER REFERENCES accruals(id) ON DELETE RESTRICT,created_by TEXT)');
$db->statement('CREATE TABLE finance_audit_log (id INTEGER PRIMARY KEY,entity_type TEXT,entity_id INTEGER,actor TEXT,details TEXT)');
$db->statement('CREATE TABLE financial_accounts (id INTEGER PRIMARY KEY,created_by TEXT)');
$db->statement('CREATE TABLE invoices (id INTEGER PRIMARY KEY,created_by TEXT)');
$db->statement('CREATE TABLE cash_transactions (id INTEGER PRIMARY KEY,created_by TEXT,confirmed_by TEXT,counterparty TEXT)');
$db->statement('CREATE TABLE payment_allocations (id INTEGER PRIMARY KEY,created_by TEXT)');
$db->statement('CREATE TABLE budget_versions (id INTEGER PRIMARY KEY,created_by TEXT,approved_by TEXT)');
$db->table('accruals')->insert([
 ['id'=>1,'employee_id'=>7,'reversal_of_accrual_id'=>null,'created_by'=>'synthetic.login'],
 ['id'=>2,'employee_id'=>8,'reversal_of_accrual_id'=>null,'created_by'=>'other.user'],
 ['id'=>3,'employee_id'=>null,'reversal_of_accrual_id'=>1,'created_by'=>'other.user'],
 ['id'=>4,'employee_id'=>null,'reversal_of_accrual_id'=>3,'created_by'=>'other.user'],
]);
$db->table('accrual_payment_links')->insert([
 ['id'=>10,'accrual_id'=>1,'created_by'=>'synthetic.login'],
 ['id'=>11,'accrual_id'=>2,'created_by'=>'other.user'],
]);
$db->table('finance_audit_log')->insert([
 ['id'=>20,'entity_type'=>'accrual','entity_id'=>1,'actor'=>'other.user','details'=>'{}'],
 ['id'=>21,'entity_type'=>'invoice','entity_id'=>70,'actor'=>'synthetic.login','details'=>'{}'],
 ['id'=>22,'entity_type'=>'invoice','entity_id'=>71,'actor'=>'other.user','details'=>'{"employee_id":7}'],
 ['id'=>23,'entity_type'=>'invoice','entity_id'=>72,'actor'=>'other.user','details'=>'{}'],
]);
$db->table('cash_transactions')->insert(['id'=>90,'created_by'=>'synthetic.login',
 'confirmed_by'=>'other.user','counterparty'=>'Synthetic Name']);
$db->table('budget_versions')->insert(['id'=>80,'created_by'=>'other.user','approved_by'=>'synthetic.login']);

$purge = new EmployeePurgeService(new EmployeePurgeRepository());
$purge->purge(7,'synthetic.login','Synthetic Name');
$purge->purge(7,'synthetic.login','Synthetic Name');
$check=static function($v,$reason){if(!$v)throw new RuntimeException($reason);};
$check($db->table('accruals')->pluck('id')->all()===[2],'Employee accrual or reversal was retained / foreign accrual destroyed');
$check($db->table('accrual_payment_links')->pluck('id')->all()===[11],'Accrual links not isolated');
$check($db->table('finance_audit_log')->pluck('id')->all()===[21,23],'Employee financial audit was not deleted correctly');
$check($db->table('finance_audit_log')->where('id',21)->value('actor')==='Удалённый сотрудник','Finance audit actor retained');
$check($db->table('cash_transactions')->count()===1,'Bank cash movement was removed');
$check($db->table('cash_transactions')->first()->counterparty==='Удалённый сотрудник','Employee counterpart retained');
$check($db->table('budget_versions')->first()->approved_by==='Удалённый сотрудник','Employee approval retained');
echo "Finance employee accrual/ref erasure, isolation and retry: PASS\n";
