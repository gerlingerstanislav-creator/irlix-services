<?php
// Exercise Laravel's actual child-process environment transfer without HTTP, DB or real secrets.
putenv('MIGRATION_METADATA_DATABASE=:memory:');
require __DIR__.'/../vendor/autoload.php';
$app=require __DIR__.'/../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$_ENV['TARGET_SHARED_DB_HOST']='synthetic-host';
$_ENV['TARGET_EMPLOYEES_DB_PASSWORD']='synthetic-password';
class EnvironmentProbe extends \Illuminate\Foundation\Console\ServeCommand
{
    protected function serverCommand() {return [PHP_BINARY,'-r','echo json_encode(["host"=>getenv("TARGET_SHARED_DB_HOST")==="synthetic-host","password"=>getenv("TARGET_EMPLOYEES_DB_PASSWORD")==="synthetic-password"]);'];}
    public function probe(bool $preserve): array
    {
        $this->input=new \Symfony\Component\Console\Input\ArrayInput($preserve ? ['--no-reload'=>true] : [],$this->getDefinition());
        $this->output=new \Illuminate\Console\OutputStyle($this->input,new \Symfony\Component\Console\Output\BufferedOutput());
        $process=$this->startProcess(true);
        $process->wait();
        if($process->getExitCode()!==0)throw new RuntimeException('Environment probe child failed.');
        return json_decode($process->getOutput(),true,512,JSON_THROW_ON_ERROR);
    }
}
$probe=new EnvironmentProbe();$probe->setLaravel($app);$probe->setApplication(new \Illuminate\Console\Application($app,$app['events'],$app->version()));
if($probe->probe(false)!==['host'=>false,'password'=>false])throw new RuntimeException('Reload regression not reproduced.');
if($probe->probe(true)!==['host'=>true,'password'=>true])throw new RuntimeException('Production environment was not preserved.');
echo "Migration HTTP child environment regression passed\n";
