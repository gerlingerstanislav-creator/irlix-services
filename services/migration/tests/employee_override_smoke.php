<?php
// Offline API + importer identity regression. All identities are synthetic.
$root=sys_get_temp_dir().'/migration-identity-'.getmypid().'-'.bin2hex(random_bytes(4));mkdir($root);
$path=$root.'/metadata.sqlite';touch($path);
putenv('MIGRATION_METADATA_DATABASE='.$path);putenv('DB_CONNECTION=sqlite');
require __DIR__.'/../vendor/autoload.php';
$app=require __DIR__.'/../bootstrap/app.php';$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
\Illuminate\Support\Facades\Artisan::call('migrate',['--force'=>true]);
function verifyIdentity(bool $ok,string $message): void {if(!$ok)throw new RuntimeException($message);}
file_put_contents($root.'/state.json',json_encode(['operation'=>['status'=>'completed'],'snapshot_operation'=>['state'=>'idle']]));
$app->bind(\App\Migration\Core\MigrationOperationsClient::class,fn()=>new \App\Migration\Core\MigrationOperationsClient(fn()=>[200,json_decode(file_get_contents($root.'/state.json'),true)]));
$failure=null;
try {
 config(['database.connections.target_employees'=>['driver'=>'sqlite','database'=>':memory:','prefix'=>'']]);
 $target=\Illuminate\Support\Facades\DB::connection('target_employees');$target->statement('CREATE TABLE employees (id INTEGER PRIMARY KEY,login TEXT,full_name TEXT)');
 $target->table('employees')->insert(['id'=>91,'login'=>'synthetic.current','full_name'=>'Synthetic Operator']);
 $store=app(\App\Migration\Core\MigrationStore::class);$run=$store->beginRun('vacations','dry-run');
 $source=['id'=>4,'email'=>'synthetic.old@example.invalid','employee_id'=>'synthetic-uuid'];
 $store->conflict($run,'vacations','users','4','SOURCE_ROW_UNRESOLVED','Synthetic unresolved login',['source'=>$source]);$store->finishRun($run,'conflicts',[]);
 $id=\Illuminate\Support\Facades\DB::table('migration_conflicts')->value('id');
 $roles=['platform-admin'];\Illuminate\Support\Facades\Http::fake(function() use (&$roles) {return \Illuminate\Support\Facades\Http::response(['data'=>['roles'=>$roles]],200);});
 $kernel=$app->make(\Illuminate\Contracts\Http\Kernel::class);
 $request=function(string $action,string $method,array $body,int $expected=200,bool $auth=true) use($kernel,$id):array {
  $r=\Illuminate\Http\Request::create('/api/migration/console/conflicts/'.$id.'/'.$action,$method,$body);$r->headers->set('Accept','application/json');if($auth)$r->headers->set('Authorization','Bearer synthetic-token');
  $response=$kernel->handle($r);verifyIdentity($response->getStatusCode()===$expected,$action.' expected '.$expected.' got '.$response->getStatusCode().': '.$response->getContent());$kernel->terminate($r,$response);return json_decode($response->getContent(),true)['data']??[];
 };
 $body=['login'=>'synthetic.current','employee_id'=>91,'source_fingerprint'=>\App\Migration\Core\EmployeeUserOverrides::fingerprint($source),'confirmation'=>'MAP USER 4'];
 $request('employee-match','GET',['login'=>'synthetic.current'],401,false);
 $roles=[];$request('employee-map','POST',$body,403);
 $roles=['platform-admin'];
 verifyIdentity($request('employee-match','GET',['login'=>'synthetic.current'])['id']===91,'Preview shows target identity');
 $request('employee-match','GET',['login'=>'synthetic.absent'],422);
 $target->table('employees')->insert(['id'=>92,'login'=>'synthetic.current']);$request('employee-match','GET',['login'=>'synthetic.current'],422);$target->table('employees')->where('id',92)->delete();
 $request('employee-map','POST',[...$body,'confirmation'=>'wrong'],422);
 $request('employee-map','POST',[...$body,'employee_id'=>92],422);
 $request('employee-map','POST',[...$body,'source_fingerprint'=>str_repeat('0',64)],422);
 $active=$store->beginRun('clients','inspect');$request('employee-map','POST',$body,409);$store->finishRun($active,'completed',[]);
 file_put_contents($root.'/state.json',json_encode(['operation'=>['status'=>'running']]));$request('employee-map','POST',$body,409);file_put_contents($root.'/state.json',json_encode(['operation'=>['status'=>'completed']]));
 $request('employee-map','POST',$body);
 verifyIdentity(\App\Migration\Core\EmployeeUserOverrides::resolve($source)===91,'Explicit alias resolves old login to target ID');
 verifyIdentity(\Illuminate\Support\Facades\DB::table('migration_run_events')->where('event','employee_override_saved')->count()===1,'Operator decision audited');
 $target->table('employees')->where('id',91)->update(['login'=>'synthetic.renamed']);verifyIdentity(\App\Migration\Core\EmployeeUserOverrides::resolve($source)===91,'Later target login change preserves identity');
 try {\App\Migration\Core\EmployeeUserOverrides::resolve([...$source,'email'=>'synthetic.changed@example.invalid']);throw new RuntimeException('Source change accepted');}catch(DomainException $e){}
 $employeeRun=$store->beginRun('employees','migrate');$store->saveMapping($employeeRun,'employees','employee','synthetic-uuid',92);$store->finishRun($employeeRun,'completed',[]);
 try {\App\Migration\Core\EmployeeUserOverrides::resolve($source);throw new RuntimeException('Contradictory UUID accepted');}catch(DomainException $e){}
 $request('employee-match','GET',['login'=>'synthetic.renamed'],422);
 $target->table('employees')->where('id',91)->delete();
 try {\App\Migration\Core\EmployeeUserOverrides::resolve($source);throw new RuntimeException('Deleted target accepted');}catch(DomainException $e){}
 $new=$store->beginRun('vacations','dry-run');$store->finishRun($new,'completed',[]);$request('employee-map','POST',[...$body,'login'=>'synthetic.renamed'],422);
 echo "Employee identity override HTTP/importer smoke passed\n";
}catch(Throwable $e){$failure=$e;}
finally {foreach(glob($root.'/*') as $file)unlink($file);rmdir($root);}
if($failure){fwrite(STDERR,$failure->getMessage()."\n");exit(1);}
