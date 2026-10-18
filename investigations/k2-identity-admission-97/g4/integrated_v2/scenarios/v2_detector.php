<?php

declare(strict_types=1);

// D-IN (PLAN.md §1): the IN assertion must reject every fixture family rebuilt with the context reverted to the source context,
// and accept the corrected rows of the same families. Exit 6 on a miss.
//   STREAMS: the archived G4 builder g4FromStream() (the real F131-01 defect) against g4v2FromStream();
//   BRU24:   the archived G4 union expression of g4Bru() against g4v2Bru();
//   HAR-B:   g4v2Row1() rows with sessionDeviceId reset to the source value (null) against the rows as built.

require dirname(__DIR__) . '/lib/g4v2.php';

use Maatify\RateLimiter\DTO\RateLimitContextDTO;

g4Provenance();

$rejects = static function (array $rows): bool {
    try {
        g4v2AssertInput($rows);
    } catch (G4V2InputViolation) {
        return true;
    }

    return false;
};

$families = [];
$rev = $cor = [];
foreach (G3_STREAMS as $id) {
    foreach (g1Strategies($id) as $s) {
        foreach (['fresh', 'const'] as $p) {
            $rev[] = g4FromStream($id, $s, $p);
            $cor[] = g4v2FromStream($id, $s, $p);
        }
    }
}
$families['STREAMS'] = [count(array_filter($rev, $rejects)), count($rev), count(array_filter($cor, $rejects)), count($cor)];

$revBru = [];
foreach (g3v2Bru24(false) as $n => $r) {
    $revBru[] = $r + ['inst' => 'x:' . $n, 'holder' => 'atk', 'ctx' => g4Ctx($r['ctx'], 'x:' . $n)];
}
$families['BRU24'] = [(int) $rejects($revBru), 1, (int) $rejects(g4v2Bru(false)), 1];

$owner = new RateLimitContextDTO('198.51.100.140', FIREFOX_130, 'acct-har', null, null, false, [], false, null);
$harCor = [];
$harRev = [];
for ($t = 0; $t < 1800; $t += 120) {
    $row = g4v2Row1($t, $owner, 'O', 'owner', 'ok', 'i:owner', 'h:owner');
    $harCor[] = $row;
    $row['ctx'] = $owner;
    $harRev[] = $row;
}
$families['HAR-B'] = [(int) $rejects($harRev), 1, (int) $rejects($harCor), 1];

$ok = true;
foreach ($families as $f => [$rr, $rn, $cr, $cn]) {
    $pass = $rr === $rn && $cr === 0;
    $ok = $ok && $pass;
    g4Emit('din', ['family' => $f, 'revertedRejected' => $rr, 'reverted' => $rn, 'correctedRejected' => $cr, 'corrected' => $cn, 'pass' => $pass]);
}
if (! $ok) {
    fwrite(STDERR, "D-IN: the input assertion did not separate reverted from corrected rows\n");
    exit(6);
}
