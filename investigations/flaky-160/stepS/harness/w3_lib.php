<?php

declare(strict_types=1);

// #160 step S — W3 instrument, loaded by w3_bootstrap.php (passed to PHPUnit as `--bootstrap`; never written into
// phpunit.xml, tests/ or composer.json). It sits on top of step M's W2 (m2b_lib.php, byte-identical except the root
// override recorded in PROVENANCE.txt) and adds two mechanical source edits, each served from a prepended autoloader
// as the on-disk text with exactly one edit, hash-pinned per arm (pins.json) and recorded in w3-transform.diff:
//   1. RateLimiterEngine::limit  -> a wrapper that calls the renamed original and records the returned decision
//      (decision, level, retryAfter, failureMode, cause, reason) or the thrown class, then returns / rethrows it.
//      The file is identical in base (main@d71c0af) and change.
//   2. EvaluationPipeline::persistWinningCandidates -> a wrapper (same parameters, no return type) that times the
//      renamed original with hrtime and records whether a store-level K4 publication call happened inside it
//      (W2's counter). base returns void, change returns array: the edit matches either return type.
// FLAKY160M_DIR (out dir) is shared with W2; each process writes w3-<pid>.json at exit. Without it nothing is written.

namespace Flaky160S {

    final class W3
    {
        public const ENGINE_FQCN = 'Maatify\\RateLimiter\\Service\\RateLimiterEngine';
        public const PIPELINE_FQCN = 'Maatify\\RateLimiter\\Service\\EvaluationPipeline';
        public const ENGINE_FROM = "    public function limit(RateLimitContextDTO \$context, RateLimitCommand \$request): RateLimitResultDTO\n    {\n";
        public const ENGINE_TO = "    public function limit(RateLimitContextDTO \$context, RateLimitCommand \$request): RateLimitResultDTO\n    {\n"
            . "        try {\n            \$__r = \$this->limitFlaky160s(\$context, \$request);\n        } catch (\\Throwable \$__e) {\n            \\Flaky160S\\W3::decision(null, \$__e);\n            throw \$__e;\n        }\n"
            . "        \\Flaky160S\\W3::decision(\$__r, null);\n\n        return \$__r;\n    }\n\n"
            . "    public function limitFlaky160s(RateLimitContextDTO \$context, RateLimitCommand \$request): RateLimitResultDTO\n    {\n";
        public const PERSIST_PARAMS = 'BlockPolicyInterface $policy, array $candidates, ?string $winningClass, bool $persistAllCandidates = false';

        /**
         * Per test: the number of results, an incremental sha256 over the canonical row sequence, and the first rows.
         * (The first dispatch kept every row in memory and died on the archive tests: 1 GiB exhausted, both arms.)
         * @var array<string, array{n: int, ctx: \HashContext, head: list<array<int,mixed>>}>
         */
        public static array $decisions = [];
        /** @var list<array{string,int,bool}> */
        public static array $persist = [];
        public static string $arm = '?';
        public static int $ownerPid = 0;

        private static function own(): void
        {
            if (self::$ownerPid !== getmypid()) {
                self::$ownerPid = getmypid();
                self::$decisions = [];
                self::$persist = [];
            }
        }

        public static function decision(?\Maatify\RateLimiter\DTO\RateLimitResultDTO $r, ?\Throwable $e): void
        {
            self::own();
            $test = \Flaky160M\M2b::test();
            $row = $r === null
                ? ['EXC', get_class($e), null, null, null, null]
                : [$r->decision, $r->blockLevel, $r->retryAfter, $r->failureMode, $r->metadata?->cause, $r->metadata?->context?->reason];
            self::$decisions[$test] ??= ['n' => 0, 'ctx' => hash_init('sha256'), 'head' => []];
            self::$decisions[$test]['n']++;
            hash_update(self::$decisions[$test]['ctx'], json_encode($row, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) . "\n");
            if (count(self::$decisions[$test]['head']) < 8) {
                self::$decisions[$test]['head'][] = $row;
            }
        }

        public static function persisted(int $ns, int $pubBefore): void
        {
            self::own();
            if (getenv('FLAKY160_TIMING') !== '1') {
                return;
            }
            self::$persist[] = [\Flaky160M\M2b::test(), $ns, \Flaky160M\M2b::$calls['publish'] > $pubBefore];
        }

        public static function flush(): void
        {
            $dir = \Flaky160M\M2b::$dir;
            if ($dir === null || self::$ownerPid !== getmypid()) {
                return;
            }
            $tests = [];
            foreach (self::$decisions as $test => $d) {
                $tests[$test] = ['n' => $d['n'], 'sha256' => hash_final($d['ctx']), 'head' => $d['head']];
            }
            file_put_contents(
                $dir . '/w3-' . getmypid() . '.json',
                json_encode(['pid' => getmypid(), 'root' => getmypid() === \Flaky160M\M2b::$rootPid, 'arm' => self::$arm,
                    'tests' => $tests, 'persist' => self::$persist], JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) . "\n",
            );
        }

        /**
         * The pipeline text with the persistWinningCandidates wrapper. Returns [text, return type] or null when the
         * signature is not found exactly once.
         * @return array{0: string, 1: string}|null
         */
        public static function pipelineEdit(string $text): ?array
        {
            $re = '/    private function persistWinningCandidates\(' . preg_quote(self::PERSIST_PARAMS, '/') . '\): (void|array)\n    \{\n/';
            if (preg_match_all($re, $text, $m) !== 1) {
                return null;
            }
            $rt = $m[1][0];
            $wrapper = "    private function persistWinningCandidates(" . self::PERSIST_PARAMS . ")\n    {\n"
                . "        \$__p = \\Flaky160M\\M2b::\$calls['publish'];\n        \$__t = hrtime(true);\n        try {\n"
                . "            return \$this->persistWinningCandidatesFlaky160s(\$policy, \$candidates, \$winningClass, \$persistAllCandidates);\n"
                . "        } finally {\n            \\Flaky160S\\W3::persisted(hrtime(true) - \$__t, \$__p);\n        }\n    }\n\n"
                . "    private function persistWinningCandidatesFlaky160s(" . self::PERSIST_PARAMS . "): $rt\n    {\n";
            $out = preg_replace($re, strtr($wrapper, ['\\' => '\\\\', '$' => '\\$']), $text, 1);

            return [(string) $out, $rt];
        }

        public static function install(string $root, ?string $dir): void
        {
            $pins = json_decode((string) file_get_contents(__DIR__ . '/pins.json'), true, 512, JSON_THROW_ON_ERROR);
            $tmp = $dir ?? sys_get_temp_dir() . '/flaky160s-' . getmypid();
            @mkdir($tmp, 0777, true);

            $engineFile = $root . '/src/Service/RateLimiterEngine.php';
            $engineText = (string) file_get_contents($engineFile);
            if (hash('sha256', $engineText) !== $pins['engine']) {
                fwrite(STDERR, "V-TRANSFORM FAIL: RateLimiterEngine.php differs from the pin\n");
                exit(3);
            }
            $count = 0;
            $engineEvaluated = str_replace(self::ENGINE_FROM, self::ENGINE_TO, $engineText, $count);
            if ($count !== 1) {
                fwrite(STDERR, "V-TRANSFORM FAIL: the engine edit matched $count times, not once\n");
                exit(3);
            }
            file_put_contents($tmp . '/RateLimiterEngine.evaluated.php', $engineEvaluated);

            $pipeFile = $root . '/src/Service/EvaluationPipeline.php';
            $pipeText = (string) file_get_contents($pipeFile);
            $arm = array_search(hash('sha256', $pipeText), $pins['pipeline'], true);
            if ($arm === false) {
                fwrite(STDERR, "V-TRANSFORM FAIL: EvaluationPipeline.php matches neither the base nor the change pin\n");
                exit(3);
            }
            self::$arm = (string) $arm;
            $edit = self::pipelineEdit($pipeText);
            if ($edit === null || ($edit[1] === 'void') !== ($arm === 'base')) {
                fwrite(STDERR, "V-TRANSFORM FAIL: the pipeline edit did not match once, or its return type contradicts the arm\n");
                exit(3);
            }
            file_put_contents($tmp . '/EvaluationPipeline.evaluated.php', $edit[0]);

            spl_autoload_register(static function (string $class) use ($tmp): void {
                if ($class === W3::ENGINE_FQCN) {
                    require $tmp . '/RateLimiterEngine.evaluated.php';
                } elseif ($class === W3::PIPELINE_FQCN) {
                    require $tmp . '/EvaluationPipeline.evaluated.php';
                }
            }, true, true);
            class_exists(self::ENGINE_FQCN);
            class_exists(self::PIPELINE_FQCN);
            if ((new \ReflectionClass(self::ENGINE_FQCN))->getFileName() === $engineFile
                || (new \ReflectionClass(self::PIPELINE_FQCN))->getFileName() === $pipeFile) {
                fwrite(STDERR, "W3 FAIL: a served class is the unmodified original\n");
                exit(3);
            }
            if ($dir !== null) {
                register_shutdown_function(static fn() => W3::flush());
            }
        }
    }
}
