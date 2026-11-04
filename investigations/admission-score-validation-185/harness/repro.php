<?php

declare(strict_types=1);

/**
 * F-185-01 reproduction: one seeded sorted-set member per arm, in the current device set, the previous device set or the
 * pending set of a settling failure set, then one `reserve` at NOW. Prints, per arm, the result and what changed in Redis.
 *
 * php investigations/admission-score-validation-185/harness/repro.php --src=<src dir> --host=127.0.0.1 --port=<port>
 */

require __DIR__ . '/lib.php';

use Maatify\RateLimiter\DTO\AdmissionPermitDTO;
use Maatify\RateLimiter\DTO\AdmissionReservationRequestDTO;
use Maatify\RateLimiter\DTO\AdmissionScopeDTO;
use Maatify\RateLimiter\Enum\AdmissionScopeKindEnum as K;
use Maatify\RateLimiter\Exception\RateLimiterException;
use Maatify\RateLimiter\Repository\Redis\RedisAdmissionOperations;

$options = f185_options();
f185_boot($options['src']);
$redis = f185_redis($options['host'], (int) $options['port']);

const NOW = 1700000000;
const CLS = 'login';

$scores = ['1700001000', '-1', '1700001000.5', 'inf', '-inf', '1e21'];
$locations = ['current', 'previous', 'pending'];

foreach ($locations as $location) {
    foreach ($scores as $score) {
        f185_raw($redis, 'FLUSHDB');
        $ops = new RedisAdmissionOperations($redis, 'f185-repro');
        $counter = new AdmissionScopeDTO('ctr', K::COUNTER, 'c:ctr', 5, 600);
        $permit = null;
        if ($location === 'pending') {
            $scope = new AdmissionScopeDTO('fail', K::SET_SIZE, 's:fail', 5, 1200, null, 'acct-new', null, false, true);
            $seedKey = $ops->pendingKey(CLS, 's:fail');
            $permit = new AdmissionPermitDTO('p-1', 'U', 'acct-new', 'login', NOW + 30, 600);
        } elseif ($location === 'previous') {
            $scope = new AdmissionScopeDTO('np', K::DEVICE_MEMBER, 'd:cur', 5, 600, 'd:prev', 'new-member', 'new-member-prev');
            $seedKey = $ops->scopeKey(CLS, K::DEVICE_MEMBER, 'd:prev');
        } else {
            $scope = new AdmissionScopeDTO('np', K::DEVICE_MEMBER, 'd:cur', 5, 600, null, 'new-member');
            $seedKey = $ops->scopeKey(CLS, K::DEVICE_MEMBER, 'd:cur');
        }
        f185_raw($redis, 'ZADD', $seedKey, $score, 'seeded');
        f185_raw($redis, 'EXPIRE', $seedKey, '600');
        $before = f185_dump($redis);
        try {
            $result = $ops->reserve(new AdmissionReservationRequestDTO(CLS, [$counter, $scope], $permit), NOW);
            $verdict = $result->admitted ? 'ADMITTED' : 'REFUSED ' . $result->refusedScope;
        } catch (RateLimiterException $e) {
            $verdict = 'RateLimiterException: ' . $e->getMessage();
        }
        $after = f185_dump($redis);
        $seed = $after[$seedKey]['value'] ?? null;
        $seedAfter = is_array($seed) ? implode(' ', $seed) : 'absent';
        $counterKey = $ops->scopeKey(CLS, K::COUNTER, 'c:ctr');
        printf(
            "%-8s score=%-13s | %s | seeded key after: [%s] ttl %s→%s | counter %s | keys %d→%d | unchanged=%s\n",
            $location,
            $score,
            $verdict,
            $seedAfter,
            $before[$seedKey]['ttl'],
            $after[$seedKey]['ttl'] ?? 'absent',
            isset($after[$counterKey]) ? (string) $after[$counterKey]['value'] : 'absent',
            count($before),
            count($after),
            $before === $after ? 'yes' : 'no',
        );
    }
}
