<?php

declare(strict_types=1);

// #160 step M — M2b instrument ("W2"), loaded by m2b_bootstrap.php, which is passed to PHPUnit on the command line
// (`--bootstrap`), never written into phpunit.xml, tests/ or composer.json. A LEAN sibling of phase 2b's W1: it
// records nothing per call. It does two things, both by serving a class from a prepended autoloader as the
// on-disk src/ text with one mechanical edit (hash-pinned, recorded in m2b-transform.diff):
//   1. RateLimitConcurrencyException: `{}` -> a constructor that calls parent::__construct(...$args) and then
//      M2b::thrown(): the `new` site (file:line of the caller) and the running test (the outermost frame whose
//      method starts with `test`) are counted. This sees every throw of the class from any store or caller,
//      including the ones the engine catches.
//   2. RedisFullCapabilityStore: the W1 edit (`final class …` -> `class …Inner`, w1's store.sha256 pin) plus a
//      subclass that overrides ONLY mutateGenerationBoundScoreAt and blockWithPunishmentLifecycleTrackingAt:
//      call parent::, count the call and, when it returns not-applied, record the test. Stores other than the
//      Redis one (the in-memory test stores) are not counted at store level; their throws are seen by (1).
// Environment: FLAKY160M_DIR (out dir). Without it nothing is written. Each process writes m2b-<pid>.json at exit;
// a forked child resets inherited state first.

namespace Flaky160M {

    final class M2b
    {
        public const EXC_FQCN = 'Maatify\\RateLimiter\\Exception\\RateLimitConcurrencyException';
        public const EXC_FROM = 'final class RateLimitConcurrencyException extends RateLimiterException {}';
        public const EXC_TO = 'final class RateLimitConcurrencyException extends RateLimiterException { public function __construct(mixed ...$args) { parent::__construct(...$args); \\Flaky160M\\M2b::thrown(); } }';
        public const EXC_SHA = 'ef65c8824d99eaad70bbc0c2d6e3cf694cd9509036cd6edd5833ca26f302091b';

        public static ?string $dir = null;
        public static int $rootPid = 0;
        public static int $ownerPid = 0;
        /** @var array<string, int> */
        public static array $calls = ['mutate' => 0, 'publish' => 0];
        /** @var list<array{kind: string, test: string}> */
        public static array $nonApplied = [];
        /** @var list<array{site: string, test: string, message: string}> */
        public static array $thrown = [];

        private static function own(): void
        {
            if (self::$ownerPid !== getmypid()) {
                self::$ownerPid = getmypid();
                self::$calls = ['mutate' => 0, 'publish' => 0];
                self::$nonApplied = [];
                self::$thrown = [];
            }
        }

        public static function test(): string
        {
            $name = '?';
            foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS) as $f) {
                if (isset($f['class'], $f['function']) && str_starts_with($f['function'], 'test') && str_ends_with($f['class'], 'Test')) {
                    $name = $f['class'] . '::' . $f['function'];
                }
            }
            return $name;
        }

        public static function thrown(): void
        {
            self::own();
            $bt = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 3);
            // [0] this call (in the constructor), [1] the constructor call: its file/line is the `new` site.
            $site = basename((string) ($bt[1]['file'] ?? '?')) . ':' . ($bt[1]['line'] ?? '?');
            self::$thrown[] = ['site' => $site, 'test' => self::test(), 'message' => ''];
        }

        public static function call(string $kind, bool $applied): void
        {
            self::own();
            self::$calls[$kind]++;
            if (! $applied) {
                // PLAN §8 (F-M-06): a store-level non-applied answer counts for the pipeline only when an
                // EvaluationPipeline frame is on the stack; the rest is `direct` (store tests), reported apart.
                $via = 'direct';
                foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS) as $f) {
                    if (($f['class'] ?? '') === 'Maatify\\RateLimiter\\Service\\EvaluationPipeline') {
                        $via = 'pipeline';
                        break;
                    }
                }
                self::$nonApplied[] = ['kind' => $kind, 'test' => self::test(), 'via' => $via];
            }
        }

        public static function flush(): void
        {
            if (self::$dir === null || self::$ownerPid !== getmypid()) {
                return;
            }
            file_put_contents(
                self::$dir . '/m2b-' . getmypid() . '.json',
                json_encode(['pid' => getmypid(), 'root' => getmypid() === self::$rootPid, 'calls' => self::$calls, 'nonApplied' => self::$nonApplied, 'thrown' => self::$thrown], JSON_UNESCAPED_SLASHES) . "\n",
            );
        }

        /** The generated subclass text (no opening tag): the two overrides, nothing else. */
        public static function subclass(string $ns, string $short): string
        {
            $out = "namespace $ns;\n\nclass $short extends {$short}Inner\n{\n";
            $out .= "    public function mutateGenerationBoundScoreAt(string \$currentKey, ?string \$previousKey, ?\\Maatify\\RateLimiter\\DTO\\GenerationBoundScoreStateDTO \$expectedState, int \$ttlSeconds, int \$newValue, int \$nowMilliseconds): \\Maatify\\RateLimiter\\DTO\\GenerationBoundScoreMutationDTO\n    {\n"
                . "        \$__r = parent::mutateGenerationBoundScoreAt(\$currentKey, \$previousKey, \$expectedState, \$ttlSeconds, \$newValue, \$nowMilliseconds);\n"
                . "        \\Flaky160M\\M2b::call('mutate', \$__r->applied);\n        return \$__r;\n    }\n\n";
            $out .= "    public function blockWithPunishmentLifecycleTrackingAt(string \$currentKey, ?string \$previousKey, int \$expectedGeneration, string \$proposedLifecycleId, int \$level, int \$durationSeconds, int \$cycleWindowSeconds, int \$cycleThreshold, int \$pauseSeconds, int \$pauseHistoryRetentionSeconds, int \$now): \\Maatify\\RateLimiter\\DTO\\PunishmentLifecycleTransitionDTO\n    {\n"
                . "        \$__r = parent::blockWithPunishmentLifecycleTrackingAt(\$currentKey, \$previousKey, \$expectedGeneration, \$proposedLifecycleId, \$level, \$durationSeconds, \$cycleWindowSeconds, \$cycleThreshold, \$pauseSeconds, \$pauseHistoryRetentionSeconds, \$now);\n"
                . "        \\Flaky160M\\M2b::call('publish', \$__r->applied);\n        return \$__r;\n    }\n}\n";

            return $out;
        }

        public static function install(string $root, ?string $dir): void
        {
            self::$dir = $dir;
            self::$rootPid = getmypid();
            $tmp = $dir ?? sys_get_temp_dir() . '/flaky160m-' . getmypid();
            @mkdir($tmp, 0777, true);

            // 1. the exception class
            $excFile = $root . '/src/Exception/RateLimitConcurrencyException.php';
            $excText = (string) file_get_contents($excFile);
            if (hash('sha256', $excText) !== self::EXC_SHA) {
                fwrite(STDERR, "V-TRANSFORM FAIL: RateLimitConcurrencyException.php differs from main@b6f4e22 (sha256 pin)\n");
                exit(3);
            }
            $count = 0;
            $excEvaluated = str_replace(self::EXC_FROM, self::EXC_TO, $excText, $count);
            if ($count !== 1) {
                fwrite(STDERR, "V-TRANSFORM FAIL: the exception edit matched $count times, not once\n");
                exit(3);
            }
            file_put_contents($tmp . '/RateLimitConcurrencyException.evaluated.php', $excEvaluated);

            // 2. the store (the W1 edit and pin, unchanged)
            $storeFile = $root . '/src/Repository/Redis/RedisFullCapabilityStore.php';
            $storeText = (string) file_get_contents($storeFile);
            $pin = trim((string) file_get_contents(__DIR__ . '/store.sha256'));
            if (hash('sha256', $storeText) !== $pin) {
                fwrite(STDERR, "V-TRANSFORM FAIL: src store file differs from the W1 pin\n");
                exit(3);
            }
            $storeEvaluated = str_replace('final class RedisFullCapabilityStore implements', 'class RedisFullCapabilityStoreInner implements', $storeText, $count);
            if ($count !== 1) {
                fwrite(STDERR, "V-TRANSFORM FAIL: the store edit matched $count times, not once\n");
                exit(3);
            }
            file_put_contents($tmp . '/RedisFullCapabilityStore.evaluated.php', $storeEvaluated);
            $ns = 'Maatify\\RateLimiter\\Repository\\Redis';
            file_put_contents($tmp . '/RedisFullCapabilityStore.m2b.php', "<?php\n\ndeclare(strict_types=1);\n\n" . self::subclass($ns, 'RedisFullCapabilityStore'));

            $storeFqcn = $ns . '\\RedisFullCapabilityStore';
            spl_autoload_register(static function (string $class) use ($tmp, $storeFqcn): void {
                if ($class === M2b::EXC_FQCN) {
                    require $tmp . '/RateLimitConcurrencyException.evaluated.php';
                } elseif ($class === $storeFqcn) {
                    require $tmp . '/RedisFullCapabilityStore.evaluated.php';
                    require $tmp . '/RedisFullCapabilityStore.m2b.php';
                }
            }, true, true);

            // Load both here, before any fork (the W1 lesson).
            class_exists(self::EXC_FQCN);
            class_exists($storeFqcn);
            if ((new \ReflectionClass(self::EXC_FQCN))->getFileName() === $excFile
                || (new \ReflectionClass($storeFqcn))->getFileName() === $storeFile
                || ! is_subclass_of($storeFqcn, $storeFqcn . 'Inner')) {
                fwrite(STDERR, "W2 FAIL: a served class is the unmodified original\n");
                exit(3);
            }
            if ($dir !== null) {
                register_shutdown_function(static fn() => M2b::flush());
            }
        }
    }
}
