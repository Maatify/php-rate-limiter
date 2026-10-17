<?php

declare(strict_types=1);

// G2 sections A and B (PLAN.md §4–§5): owner acquisition while U_acct is exhausted and the proof path is attacked.

require dirname(__DIR__) . '/lib/sim.php';

$emit = static function (array $row): void {
    echo json_encode(['s' => 'ab'] + $row, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), "\n";
};
$base = ['T' => 3600, 'x' => 1, 'nSrc' => 30, 'coloc' => false, 'glob' => false];
$fixtures = [
    'ACQ' => [$base + ['fixture' => 'ACQ'], ['NOPROOF', 'PA', 'PB', 'N-A-ACCTCAP', 'N-A-SINGLE', 'N-U', 'N-B-BOUND', 'N-B-SHORT', 'N-B-TTL', 'N-B-SHAREDOP', 'N-B-INVAL'], G2_ARRIVALS],
    'ACQ-x10' => [['fixture' => 'ACQ-x10', 'x' => 10, 'nSrc' => 300] + $base, ['NOPROOF', 'PA', 'PB'], G2_ARRIVALS],
    'ACQ-LONG' => [['fixture' => 'ACQ-LONG', 'T' => 14400] + $base, ['NOPROOF', 'PA', 'PB'], g2LongArrivals()],
    'COLOC' => [['fixture' => 'COLOC', 'coloc' => true] + $base, ['PA', 'PB'], G2_ARRIVALS],
    'GLOB' => [['fixture' => 'GLOB', 'x' => 10, 'nSrc' => 300, 'glob' => true] + $base, ['PA', 'PB'], G2_ARRIVALS],
];
foreach ($fixtures as [$fx, $arms, $arrivals]) {
    foreach ($arms as $arm) {
        foreach ($arrivals as $a) {
            $emit(g2RunAcq($arm, $fx, $a));
        }
    }
}
