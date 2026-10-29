<?php

require __DIR__.'/../app/Support/ClientContourAccess.php';
use App\Support\ClientContourAccess as Access;

$check=static function(bool $valid,string $reason):void {
    if (!$valid) throw new RuntimeException($reason);
};
$check(Access::verifiedLegacyManagerMatrixRole(
    ['employee-id:41','org:irlix','head:backend'],true
) === ['department-manager'], 'Current signed head did not preserve manager grants');
$check(Access::verifiedLegacyManagerMatrixRole(
    ['employee-id:41','org:irlix','head:account-management'],true
) === ['department-manager'], 'Signed account-management head mapping was lost');
$check(Access::verifiedLegacyManagerMatrixRole(
    ['org:irlix','service:clients','department-manager'],true
) === [], 'Legacy marker bypassed signed head requirement');
$check(Access::verifiedLegacyManagerMatrixRole(
    ['head:backend'],false
) === [], 'Former manager retained delegated matrix access');
$check(Access::verifiedLegacyManagerMatrixRole(
    ['head:../../admin'],true
) === [], 'Invalid head role accepted');
echo "Clients verified legacy manager bridge: PASS\n";
