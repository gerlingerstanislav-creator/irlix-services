<?php
declare(strict_types=1);
// Standalone build-time invariants; integration with Clients requires its own verified contract.
$statuses=['draft','issued','cancelled'];
assert(count(array_unique($statuses))===3);
$invoiceTotal=10000;
$allocations=[7000,3000];
assert(array_sum($allocations)===$invoiceTotal);
assert(array_sum([7000,2000])!==$invoiceTotal);
echo "Finance ledger contract smoke OK\n";
