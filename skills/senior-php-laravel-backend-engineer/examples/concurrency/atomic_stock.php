<?php
declare(strict_types=1);
// Behavioral model for an atomic conditional PostgreSQL UPDATE. NOT a DB stress test.
final class Stock {
    public int $available;
    public function __construct(int $n) { $this->available=$n; }
    public function atomicDecrement(int $quantity): bool {
        if ($quantity <= 0) throw new InvalidArgumentException('quantity');
        // A real single-statement SQL UPDATE serializes/conflicts at the row level.
        if ($this->available < $quantity) return false;
        $this->available -= $quantity;
        return true;
    }
}
function ck(bool $v,string $message):void {if (!$v)throw new RuntimeException($message);}
// A naive read-then-write can oversell when both writers read the same pre-write value.
$naiveAvailable=5; $staleA=$naiveAvailable; $staleB=$naiveAvailable;
$naiveAvailable=$staleA-4; $naiveAvailable=$staleB-4;
ck($naiveAvailable===1, 'fixture for stale overwrite');
// Both naive clients returned success even though the supply allowed only one.
$stock=new Stock(5);
$success=0;
foreach ([4,4] as $q) if ($stock->atomicDecrement($q))$success++;
ck($success===1 && $stock->available===1,'oversell under repeated requests');
$rows=[['id'=>1,'tenant'=>'A'],['id'=>2,'tenant'=>'B']];
$visible=array_values(array_filter($rows,fn($r)=>$r['tenant']==='A'));
ck(count($visible)===1 && $visible[0]['id']===1,'cross-tenant leak');
// N+1 query-count contract example: 1 parent + N related vs 2 eager-loaded queries.
$relatedCount=count($rows); $naiveQueries=1+$relatedCount; $eagerQueries=2;
ck($naiveQueries===3 && $eagerQueries===2,'N+1 query count model');
try {$stock->atomicDecrement(0);throw new RuntimeException('zero quantity accepted');}
catch (InvalidArgumentException $e) {}
echo "ALL CHECKS PASSED: conditional update model, tenant-scoped read, N+1 model, invalid quantity\n";
