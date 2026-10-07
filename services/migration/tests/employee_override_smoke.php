<?php
// Offline API + importer identity regression. All identities are synthetic.
$root=sys_get_temp_dir().'/migration-identity-'.getmypid().'-'.bin2hex(random_bytes(4));mkdir($root);
$path=$root.'/metadata.sqlite';touch($path);
putenv('MIGRATION_IDENTITY_DATABASE='.$root.'/identity.sqlite');putenv('MIGRATION_METADATA_DATABASE='.$path);putenv('DB_CONNECTION=sqlite');
require __DIR__.'/../vendor/autoload.php';
$app=require __DIR__.'/../bootstrap/app.php';$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
\Illuminate\Support\Facades\Artisan::call('migrate',['--force'=>true]);
function verifyIdentity(bool $ok,string $message): void {if(!$ok)throw new RuntimeException($message);}
file_put_contents($root.'/state.json',json_encode(['operation'=>['status'=>'completed'],'snapshot_operation'=>['state'=>'idle']]));
$opsUnavailable=false;
$app->bind(\App\Migration\Core\MigrationOperationsClient::class,function() use($root,&$opsUnavailable) {return new \App\Migration\Core\MigrationOperationsClient(function() use($root,&$opsUnavailable) {if($opsUnavailable)throw new RuntimeException('synthetic-private-error');return [200,json_decode(file_get_contents($root.'/state.json'),true)];});});
$failure=null;
try {
 config(['database.connections.target_employees'=>['driver'=>'sqlite','database'=>':memory:','prefix'=>'']]);
 $target=\Illuminate\Support\Facades\DB::connection('target_employees');$target->statement('CREATE TABLE employees (id INTEGER PRIMARY KEY,login TEXT,full_name TEXT)');
 $target->table('employees')->insert(['id'=>91,'login'=>'synthetic.current','full_name'=>'Synthetic Operator']);
 verifyIdentity(\Illuminate\Support\Facades\Artisan::call('migration:employee-query-check')===0,'Runtime Employees contract query succeeds without identities');
 $store=app(\App\Migration\Core\MigrationStore::class);$run=$store->beginRun('vacations','dry-run');
 $source=['id'=>4,'email'=>'synthetic.old@example.invalid','employee_id'=>'synthetic-uuid'];
 $store->conflict($run,'vacations','users','4','SOURCE_ROW_UNRESOLVED','Synthetic unresolved login',['source'=>$source]);$store->finishRun($run,'conflicts',[]);
 $id=\Illuminate\Support\Facades\DB::table('migration_conflicts')->value('id');
 $roles=['platform-admin'];\Illuminate\Support\Facades\Http::fake(function() use (&$roles) {return \Illuminate\Support\Facades\Http::response(['data'=>['roles'=>$roles]],200);});
 $kernel=$app->make(\Illuminate\Contracts\Http\Kernel::class);
 $responseBody=[];
 $request=function(string $action,string $method,array $body,int $expected=200,bool $auth=true) use($kernel,$id,&$responseBody):array {
  $r=\Illuminate\Http\Request::create('/api/migration/console/conflicts/'.$id.'/'.$action,$method,$body);$r->headers->set('Accept','application/json');if($auth)$r->headers->set('Authorization','Bearer synthetic-token');
  $response=$kernel->handle($r);verifyIdentity($response->getStatusCode()===$expected,$action.' expected '.$expected.' got '.$response->getStatusCode().': '.$response->getContent());$kernel->terminate($r,$response);$responseBody=json_decode($response->getContent(),true);return $responseBody['data']??[];
 };
 $body=['login'=>'synthetic.current','employee_id'=>91,'source_fingerprint'=>\App\Migration\Core\EmployeeUserOverrides::fingerprint($source),'confirmation'=>'MAP USER 4'];
 $request('employee-match','GET',['login'=>'synthetic.current'],401,false);
 $roles=[];$request('employee-map','POST',$body,403);
 $roles=['platform-admin'];
 verifyIdentity($request('employee-match','GET',['login'=>'synthetic.current'])['id']===91,'Preview shows target identity');
 verifyIdentity($request('employee-match','GET',['login'=>' SYNTHETIC.CURRENT '])['id']===91,'Login case and edge spaces ignored');
 $request('employee-match','GET',['login'=>'synthetic.currеnt'],422); // Cyrillic е must not silently select a different identity.
 $request('employee-match','GET',['login'=>'synthetic.absent'],422);
 $opsUnavailable=true;$request('employee-match','GET',['login'=>'synthetic.current'],503);
 verifyIdentity($responseBody['stage']==='queue' && isset($responseBody['diagnostic_id']),'Queue outage identifies stage and diagnostic ID');
 verifyIdentity(!str_contains(json_encode($responseBody),'synthetic-private-error'),'Exception details are not disclosed');
 $request('employee-map','POST',$body,503);verifyIdentity($responseBody['stage']==='queue','Save queue outage identifies stage');
 $opsUnavailable=false;
 $target->statement('ALTER TABLE employees RENAME TO synthetic_unavailable');
 verifyIdentity(\Illuminate\Support\Facades\Artisan::call('migration:employee-query-check')===1,'Runtime query detects target schema outage');
 verifyIdentity(!str_contains(\Illuminate\Support\Facades\Artisan::output(),'Synthetic Operator'),'Runtime diagnostic excludes employee rows');
 $request('employee-match','GET',['login'=>'synthetic.current'],503);
 verifyIdentity($responseBody['stage']==='employee' && $responseBody['code']==='EMPLOYEE_MAPPING_EMPLOYEE_FAILED','Target query outage differs from missing login');
 verifyIdentity(!str_contains(json_encode($responseBody),'synthetic.current') && !str_contains(json_encode($responseBody),'select '),'Response excludes SQL and bindings');
 $target->statement('ALTER TABLE synthetic_unavailable RENAME TO employees');
 \Illuminate\Support\Facades\DB::statement('ALTER TABLE migration_conflicts RENAME TO synthetic_conflicts_unavailable');
 $request('employee-match','GET',['login'=>'synthetic.current'],503);verifyIdentity($responseBody['stage']==='source','Metadata outage identifies source stage');
 \Illuminate\Support\Facades\DB::statement('ALTER TABLE synthetic_conflicts_unavailable RENAME TO migration_conflicts');
 $dbError=new PDOException('synthetic-private-sql-error',7);$dbError->errorInfo=['42501',7,'synthetic-private-driver-error'];
 verifyIdentity(\App\Migration\Core\EmployeeMappingFailure::sqlState($dbError)==='42501','Postgres driver errorInfo overrides numeric exception code');
 $dbResponse=\App\Migration\Core\EmployeeMappingFailure::response($dbError,'employee');
 verifyIdentity(str_contains($dbResponse->getContent(),'42501') && !str_contains($dbResponse->getContent(),'synthetic-private-sql-error'),'SQLSTATE diagnostic excludes exception message');

 $target->table('employees')->insert(['id'=>92,'login'=>'synthetic.current']);$request('employee-match','GET',['login'=>'synthetic.current'],422);$target->table('employees')->where('id',92)->delete();
 $request('employee-map','POST',[...$body,'confirmation'=>'wrong'],422);
 $request('employee-map','POST',[...$body,'employee_id'=>92],422);
 $request('employee-map','POST',[...$body,'source_fingerprint'=>str_repeat('0',64)],422);
 $active=$store->beginRun('clients','inspect');$request('employee-map','POST',$body,409);$store->finishRun($active,'completed',[]);
 file_put_contents($root.'/state.json',json_encode(['operation'=>['status'=>'running']]));$request('employee-map','POST',$body,409);file_put_contents($root.'/state.json',json_encode(['operation'=>['status'=>'completed']]));
 $request('employee-map','POST',$body);
 verifyIdentity(\App\Migration\Core\EmployeeUserOverrides::resolve($source)===91,'Explicit alias resolves old login to target ID');
 verifyIdentity(\Illuminate\Support\Facades\DB::table('migration_run_events')->where('event','employee_override_saved')->count()===1,'Operator decision audited');
 \Illuminate\Support\Facades\DB::table('migration_overrides')->delete();
 $target->table('employees')->where('id',91)->delete();
 try {\App\Migration\Core\EmployeeUserOverrides::resolve($source);throw new RuntimeException('Missing employee accepted');}catch(DomainException $e){verifyIdentity(str_contains($e->getMessage(),'Сопоставление сохранено'),'Missing employee preserves decision');}
 $target->table('employees')->insert(['id'=>101,'login'=>'synthetic.current','full_name'=>'Synthetic Operator']);
 verifyIdentity(\App\Migration\Core\EmployeeUserOverrides::resolve($source)===101,'Rollback and changed numeric ID preserve login alias');
 config(['migration.legacy.vacations.database.host'=>'synthetic-other-source']);
 verifyIdentity(\App\Migration\Core\EmployeeUserOverrides::resolve($source)===null,'Other source never inherits alias');
 config(['migration.legacy.vacations.database.host'=>'']);
 verifyIdentity(\App\Migration\Core\EmployeeUserOverrides::resolve($source)===101,'Original source alias remains');
 $target->table('employees')->where('id',101)->update(['login'=>'synthetic.renamed']);
 try {\App\Migration\Core\EmployeeUserOverrides::resolve($source);throw new RuntimeException('Renamed login accepted');}catch(DomainException $e){}
 \App\Migration\Core\EmployeeLoginRegistry::save($source,'synthetic.renamed','synthetic-operator');
 verifyIdentity(\App\Migration\Core\EmployeeUserOverrides::resolve($source)===101,'Explicitly corrected alias resolves renamed login');
 verifyIdentity(\App\Migration\Core\EmployeeUserOverrides::resolve([...$source,'email'=>'synthetic.changed@example.invalid'])===null,'Changed old login requires new alias');
 $employeeRun=$store->beginRun('employees','migrate');$store->saveMapping($employeeRun,'employees','employee','synthetic-uuid',92);$store->finishRun($employeeRun,'completed',[]);
 try {\App\Migration\Core\EmployeeUserOverrides::resolve($source);throw new RuntimeException('Contradictory UUID accepted');}catch(DomainException $e){}
 $request('employee-match','GET',['login'=>'synthetic.renamed'],422);
 \Illuminate\Support\Facades\DB::table('migration_mappings')->where('service','employees')->delete();
 $target->table('employees')->where('id',101)->delete();
 try {\App\Migration\Core\EmployeeUserOverrides::resolve($source);throw new RuntimeException('Deleted target accepted');}catch(DomainException $e){}
 $new=$store->beginRun('vacations','dry-run');$store->finishRun($new,'completed',[]);$request('employee-map','POST',[...$body,'login'=>'synthetic.renamed'],422);
 echo "Employee identity override HTTP/importer smoke passed\n";
}catch(Throwable $e){$failure=$e;}
finally {foreach(glob($root.'/*') as $file)unlink($file);rmdir($root);}
if($failure){fwrite(STDERR,$failure->getMessage()."\n");exit(1);}
