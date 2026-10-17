<?php

declare(strict_types=1);

// G1 targeted step — race worker for the FIXED Redis store (T1-N6): one reservation released at a shared barrier instant.
//   t1_worker.php <port> <barrier-unix-float> <counter|device> <index>

require dirname(__DIR__) . '/lib/t1v2.php';

[$_, $port, $barrier, $case, $idx] = $argv;
$r = new \Redis();
$r->connect('127.0.0.1', (int) $port, 2.0);
$p = ['U_src' => 5, 'U_acct' => 100, 'U_glob' => 0, 'N_P' => 5, 'P_acct' => 1000, 'P_acct_day' => 1000, 'P_proof' => 1000, 'P_proof_day' => 1000] + g1Params('R');
$a = new G1Admission(new G1RedisStoreV2($r), $p);
while (microtime(true) < (float) $barrier) {
    usleep(200);
}
$now = 1767268800 + 60;
$res = $case === 'counter'
    ? $a->reserve('U', '198.51.100.200', "racer$idx", null, null, $now)
    : $a->reserve('P', '198.51.100.201', 'acctRace', "sd-race$idx", "fp-race$idx", $now);
echo $res['ok'] ? '1' : '0';
