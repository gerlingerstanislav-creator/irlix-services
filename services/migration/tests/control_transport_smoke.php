<?php
// Exercise actual HTTP over a UNIX socket, including state/report payloads above the former cap.
require __DIR__.'/../app/Migration/Core/MigrationOperationsClient.php';
$path = '/tmp/control-transport-'.getmypid().'.sock';
$probe = @stream_socket_server('unix://'.$path,$probeError,$probeMessage);
if (!$probe) {
    if (str_contains($probeMessage,'Operation not permitted')) { echo "NOT TESTED: host prohibits UNIX socket creation.\n"; exit(0); }
    throw new RuntimeException('Cannot create test socket: '.$probeMessage);
}
fclose($probe); @unlink($path);
$server = <<<'SERVER'
$socket = stream_socket_server('unix://'.$argv[1],$errno,$error);
if (!$socket) exit(1);
echo "ready\n"; flush();
for ($i=0;$i<3;$i++) {
    $peer = stream_socket_accept($socket,10);
    if (!$peer) exit(2);
    $headers = '';
    while (($line=fgets($peer)) !== false) { $headers.=$line; if ($line==="\r\n") break; }
    if (preg_match('/Content-Length: (\d+)/i',$headers,$length)) fread($peer,(int)$length[1]);
    $payload = json_encode(['history'=>[['id'=>'123','proof'=>str_repeat('x',300000)]]]);
    $response = "HTTP/1.0 200 OK\r\nContent-Type: application/json\r\nContent-Length: ".strlen($payload)."\r\n\r\n".$payload;
    while ($response!=='') { $written=fwrite($peer,$response); if (!$written) exit(3); $response=substr($response,$written); }
    fclose($peer);
}
fclose($socket); unlink($argv[1]);
SERVER;
$process = proc_open([PHP_BINARY,'-r',$server,'--',$path],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
try {
    if (!is_resource($process) || trim(fgets($pipes[1]))!=='ready') throw new RuntimeException('Test socket server failed');
    $client = new \App\Migration\Core\MigrationOperationsClient(null,$path);
    foreach (['/control/state','/console/state','/control/operations/123/report'] as $route) {
        [$status,$body] = $client->request('GET',$route);
        if ($status!==200 || strlen($body['history'][0]['proof'])!==300000) throw new RuntimeException('Large payload truncated: '.$route);
    }
    foreach ($pipes as $pipe) fclose($pipe);
    if (proc_close($process)!==0) throw new RuntimeException('Socket server failed');
    $process = null;
    echo "Control UNIX socket transport passed: control state, old console state and full report exceed 128KB.\n";
} finally {
    if (is_resource($process)) { proc_terminate($process); proc_close($process); }
    @unlink($path);
}
