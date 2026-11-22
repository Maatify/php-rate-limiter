<?php

declare(strict_types=1);

/**
 * #284 study harness (study only; not part of the package, not run by PHPUnit/CI).
 *
 * Runs the PUBLIC writer (RateLimiterBuilder::build()->limit()) and the PUBLIC reader (buildOperationalReader()->readScorePolicy())
 * against a real Redis, with synthetic data only. Expects: /work = clean clone at the base SHA, /deps/vendor = composer runtime deps,
 * REDIS_INTEGRATION_HOST / REDIS_INTEGRATION_PORT. Exit 0 only when every control holds; the demonstration (D*) section is reported
 * separately and never changes the exit code (it records a proven gap, it is not a pass/fail control of the current contract).
 */

use Maatify\RateLimiter\Builder\RateLimiterBuilder;
use Maatify\RateLimiter\Command\RateLimitCommand;
use Maatify\RateLimiter\Config\RateLimiterConfig;
use Maatify\RateLimiter\DTO\RateLimitContextDTO;
use Maatify\RateLimiter\Repository\Redis\RedisFullCapabilityStore;
use Maatify\RateLimiter\Tests\Support\Clock\FixedClock;
use Maatify\RateLimiter\Tests\Support\FailureSignal\RecordingFailureSignalEmitter;
use Maatify\RateLimiter\Tests\Support\Redis\RespRedisCommandExecutor;

$root = $argv[1] ?? '/work';
require '/deps/vendor/autoload.php';
spl_autoload_register(static function (string $class) use ($root): void {
    foreach (['Maatify\\RateLimiter\\Tests\\' => '/tests/', 'Maatify\\RateLimiter\\' => '/src/'] as $ns => $dir) {
        if (str_starts_with($class, $ns)) {
            $file = $root . $dir . str_replace('\\', '/', substr($class, strlen($ns))) . '.php';
            if (is_file($file)) {
                require $file;
            }
            return;
        }
    }
});

const POLICY = 'login_protection';
const SECRET = 'study-284-key';
const FPSECRET = 'study-284-fp';
const CONTAINER_PREFIX = 'study284';

$exec = new RespRedisCommandExecutor((string) getenv('REDIS_INTEGRATION_HOST'), (int) getenv('REDIS_INTEGRATION_PORT'));
$results = [];
$failed = 0;

function record(string $id, bool $ok, array $detail, bool $control = true): void
{
    global $results, $failed;
    $results[] = ['id' => $id, 'kind' => $control ? 'control' : 'demonstration', 'ok' => $ok] + $detail;
    if ($control && ! $ok) {
        $failed++;
    }
}

/** @return array{limiter: object, reader: object} */
function runtime(RespRedisCommandExecutor $exec, string $env, string $secret = SECRET): array
{
    $builder = RateLimiterBuilder::fromFullCapabilityStore(
        new RateLimiterConfig($secret, FPSECRET, $env),
        new RedisFullCapabilityStore($exec, CONTAINER_PREFIX),
        new RecordingFailureSignalEmitter(),
    )->withClock(new FixedClock('2026-11-04 12:00:00'));

    return ['limiter' => $builder->build(), 'reader' => $builder->buildOperationalReader()];
}

function ctx(string $account): RateLimitContextDTO
{
    return new RateLimitContextDTO('198.51.100.7', 'Mozilla/5.0 Chrome/120.0', $account);
}

function k4Score(array $rt, string $account): int
{
    $snap = $rt['reader']->readScorePolicy(ctx($account), POLICY);

    return $snap->scopes->k4?->score?->value ?? 0;
}

function writeFailures(array $rt, string $account, int $n): void
{
    for ($i = 0; $i < $n; $i++) {
        $rt['limiter']->limit(ctx($account), RateLimitCommand::checkOnly(POLICY));
        $rt['limiter']->limit(ctx($account), RateLimitCommand::recordFailure(POLICY));
    }
}

function flushDb(RespRedisCommandExecutor $exec): void
{
    $exec->execute(['FLUSHDB']);
}

function dbsize(RespRedisCommandExecutor $exec): int
{
    $n = $exec->execute(['DBSIZE']);
    assert(is_int($n));

    return $n;
}

/** The V2 K4 preimage exactly as the writer and the reader build it today (EvaluationPipeline.php:3111, RateLimitOperationalReader.php:460). */
function k4Preimage(string $policy, string $env, string $account): string
{
    return "{$policy}:rate_limiter:k4:v2:{$env}:{$account}";
}

function k4Key(string $env, string $account, string $secret = SECRET): string
{
    return hash_hmac('sha256', k4Preimage(POLICY, $env, $account), $secret);
}

function keyExists(RespRedisCommandExecutor $exec, string $hmac): bool
{
    $keys = $exec->execute(['KEYS', '*']);
    foreach ((array) $keys as $k) {
        // physical key = PREFIX:sha256(storeNamespace):family:sha256(logical key); logical key = the HMAC hex (RedisFullCapabilityStore.php:3137)
        if (is_string($k) && str_ends_with($k, ':' . hash('sha256', $hmac))) {
            return true;
        }
    }

    return false;
}

// ---- Environment log (from inside the container) ----------------------------------------------------------------------------
$info = (string) $exec->execute(['INFO', 'server']);
preg_match('/redis_version:(\S+)/', $info, $m);
$env = [
    'php' => PHP_VERSION, 'php_int_size' => PHP_INT_SIZE, 'ext_redis' => phpversion('redis') ?: null,
    'redis_server' => $m[1] ?? null, 'source_head' => trim((string) @shell_exec('git -C ' . escapeshellarg($root) . ' rev-parse HEAD')),
    'source_tree' => trim((string) @shell_exec('git -C ' . escapeshellarg($root) . ' rev-parse HEAD^{tree}')),
];

// ---- C1: writer and reader derive the same K4 for the same scope; the input reaches the pipeline ------------------------------
flushDb($exec);
$a = runtime($exec, 'prod');
writeFailures($a, 'acct-1', 3);
$expectedKey = k4Key('prod', 'acct-1');
$score = k4Score($a, 'acct-1');
record('C1_writer_reader_same_scope', $score > 0 && keyExists($exec, $expectedKey) && dbsize($exec) > 0, [
    'inputs' => ['env' => 'prod', 'account' => 'acct-1', 'failures' => 3], 'reader_k4_score' => $score,
    'independent_hmac_key_exists_in_redis' => keyExists($exec, $expectedKey), 'dbsize' => dbsize($exec),
]);

// ---- C2: normal separation (other account / other env / other policy name) -------------------------------------------------
$sameEnvOtherAccount = k4Score($a, 'acct-2');
$otherEnv = runtime($exec, 'staging');
$otherEnvSeesIt = k4Score($otherEnv, 'acct-1');
record('C2_separation_other_account_other_env', $sameEnvOtherAccount === 0 && $otherEnvSeesIt === 0, [
    'reader_same_env_other_account' => $sameEnvOtherAccount, 'reader_other_env_same_account' => $otherEnvSeesIt,
]);

// ---- C3: a second writer in another env writes a distinct key, the first env does not see it --------------------------------
writeFailures($otherEnv, 'acct-1', 1);
$prodStill = k4Score($a, 'acct-1');
$stagingOwn = k4Score($otherEnv, 'acct-1');
record('C3_two_envs_isolated_counters', $prodStill !== $stagingOwn && $stagingOwn > 0 && keyExists($exec, k4Key('staging', 'acct-1')), [
    'prod_score' => $prodStill, 'staging_score' => $stagingOwn,
]);

// ---- C4: preimage determinism (same inputs -> same bytes; account is the last field so one env never collides with itself) ---
$same = k4Preimage(POLICY, 'prod', 'a:b') === k4Preimage(POLICY, 'prod', 'a:b');
$differentAccountsSameEnv = k4Preimage(POLICY, 'prod', 'a') !== k4Preimage(POLICY, 'prod', 'a:b')
    && k4Preimage(POLICY, 'prod', 'a:b') !== k4Preimage(POLICY, 'prod', 'a:c');
record('C4_single_env_distinct_accounts_distinct_preimages', $same && $differentAccountsSameEnv, ['note' => 'account is the trailing field']);

// ---- D1: DEMONSTRATION of the proven gap: env "prod" + account "a:b"  ==  env "prod:a" + account "b" ------------------------
flushDb($exec);
$x = runtime($exec, 'prod');
$y = runtime($exec, 'prod:a');
$pre1 = k4Preimage(POLICY, 'prod', 'a:b');
$pre2 = k4Preimage(POLICY, 'prod:a', 'b');
writeFailures($x, 'a:b', 3);
$seenByY = k4Score($y, 'b');
$yWrongScoreBeforeAnyOwnWrite = $seenByY;
$keysBefore = dbsize($exec);
$yDecision = $y['limiter']->limit(ctx('b'), RateLimitCommand::checkOnly(POLICY));
writeFailures($y, 'b', 1);
record('D1_cross_env_collision_k4', $pre1 === $pre2, [
    'preimage_env_prod_account_a:b' => $pre1, 'preimage_env_prod:a_account_b' => $pre2, 'preimages_equal' => $pre1 === $pre2,
    'same_hmac' => k4Key('prod', 'a:b') === k4Key('prod:a', 'b'),
    'writer_x_failures' => 3, 'reader_y_score_for_account_b_before_any_own_write' => $yWrongScoreBeforeAnyOwnWrite,
    'reader_y_view_of_b_hard_block_level' => $y['reader']->readScorePolicy(ctx('b'), POLICY)->scopes->k4?->activeHardBlock?->level,
    'writer_y_checkOnly_decision_for_account_b' => $yDecision->decision, 'writer_y_block_level' => $yDecision->blockLevel,
    'dbsize_after_x' => $keysBefore, 'dbsize_after_y_write' => dbsize($exec), 'score_after_y_write_seen_by_x' => k4Score($x, 'a:b'),
], false);

// ---- D2: pure preimage collisions in the sibling families built with the same pattern (no Redis involved) ---------------------
$aux1 = 'login_protection:rate_limiter:aux:anti_equilibrium:v1:prod:a:b';
$aux2 = 'login_protection:rate_limiter:aux:anti_equilibrium:v1:' . 'prod:a' . ':b';
$cool1 = 'login_protection:rate_limiter:budget_cooldown:v1:' . 'prod' . ':' . 'a:b';
$cool2 = 'login_protection:rate_limiter:budget_cooldown:v1:' . 'prod:a' . ':' . 'b';
record('D2_sibling_families_preimage', true, ['aux_equal' => $aux1 === $aux2, 'budget_cooldown_equal' => $cool1 === $cool2], false);

// ---- D3: the domain of accepted configuration values ------------------------------------------------------------------------
$accepted = [];
foreach (['prod:a', 'a:b:c', ':', "tab\tenv", 'ünï', 'x:rate_limiter:k4:v2:y'] as $v) {
    try {
        new RateLimiterConfig('s', 'f', $v);
        $accepted[$v] = true;
    } catch (\Throwable $e) {
        $accepted[$v] = false;
    }
}
record('D3_environment_scope_domain', true, ['accepted' => $accepted], false);

echo json_encode(['environment' => $env, 'results' => $results, 'control_failures' => $failed], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), "\n";
exit($failed === 0 ? 0 : 1);
