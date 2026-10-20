<?php
declare(strict_types=1);
// In-memory transactional fixture: proves control flow, NOT RabbitMQ or PostgreSQL durability.
final class FakeUnitOfWork {
    public array $balances=['entity-1'=>0];
    public array $processed=[];
    public bool $failNextApply=false;
    private ?array $snapshot=null;
    public function begin(): void { if ($this->snapshot!==null) throw new RuntimeException('nested tx'); $this->snapshot=[$this->balances,$this->processed]; }
    public function seen(string $eventId): bool { return isset($this->processed[$eventId]); }
    public function apply(string $entity, int $delta): void { if ($this->failNextApply) { $this->failNextApply=false; throw new RuntimeException('temporary DB outage'); } $this->balances[$entity]+=$delta; }
    public function mark(string $eventId): void {$this->processed[$eventId]=true;}
    public function commit(): void {$this->snapshot=null;}
    public function rollback(): void { if ($this->snapshot !== null) {[$this->balances,$this->processed]=$this->snapshot;$this->snapshot=null;} }
}
final class FakeBroker {
    public int $acks=0;
    public int $retries=0;
    public int $dead=0;
    public bool $simulateLostAck=false;
    public function ack():void { if ($this->simulateLostAck) { $this->simulateLostAck=false; throw new RuntimeException('ack lost'); } $this->acks++;}
    public function retry():void {$this->retries++;}
    public function deadLetter():void {$this->dead++;}
}
function handle(array $e, FakeUnitOfWork $uow, FakeBroker $broker): void {
    if (!isset($e['event_id'],$e['entity'],$e['delta']) || !is_string($e['event_id']) || $e['event_id']==='' || !is_string($e['entity']) || !is_int($e['delta'])) {
        $broker->deadLetter(); return; // permanent invalid payload
    }
    try {
        $uow->begin();
        if (!$uow->seen($e['event_id'])) {
            if ($e['entity']!=='entity-1') throw new DomainException('unknown entity');
            $uow->apply($e['entity'], $e['delta']);
            $uow->mark($e['event_id']);
        }
        $uow->commit();
    } catch (DomainException $ex) {
        $uow->rollback(); $broker->deadLetter(); return;
    } catch (Throwable $ex) {
        $uow->rollback(); $broker->retry(); return;
    }
    // ACK after durable commit. An ACK failure triggers broker redelivery.
    $broker->ack();
}
function ck(bool $v,string $message):void {if (!$v)throw new RuntimeException($message);}
$u=new FakeUnitOfWork();$b=new FakeBroker();
$event=['event_id'=>'E1','entity'=>'entity-1','delta'=>7];
handle($event,$u,$b);handle($event,$u,$b);
ck($u->balances['entity-1']===7 && $b->acks===2,'duplicate work applied');
$b->simulateLostAck=true;
try { handle(['event_id'=>'E2','entity'=>'entity-1','delta'=>3],$u,$b); throw new RuntimeException('missing ack exception'); }
catch (RuntimeException $ex) {ck($ex->getMessage()==='ack lost','wrong error');}
handle(['event_id'=>'E2','entity'=>'entity-1','delta'=>3],$u,$b);
ck($u->balances['entity-1']===10,'redelivery replay duplicated effect');
handle(['event_id'=>'E3','entity'=>'missing','delta'=>10],$u,$b);
handle(['entity'=>'entity-1'], $u, $b);
ck($b->dead===2 && !isset($u->processed['E3']),'poison message mishandled');
$u->failNextApply=true;
handle(['event_id'=>'E4','entity'=>'entity-1','delta'=>11],$u,$b);
ck($b->retries===1 && !isset($u->processed['E4']) && $u->balances['entity-1']===10,'rollback/retry not safe');
handle(['event_id'=>'E4','entity'=>'entity-1','delta'=>11],$u,$b);
ck($u->balances['entity-1']===21 && isset($u->processed['E4']),'redelivery after transient failure lost');
echo "ALL CHECKS PASSED: duplicate, commit-before-ACK, lost ACK, rollback and transient retry, DLQ invalid/unknown payload\n";
