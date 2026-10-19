<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\Support\Admission;

use Maatify\RateLimiter\DTO\RateLimitContextDTO;
use Maatify\RateLimiter\Enum\AdmissionLaneEnum;
use Maatify\RateLimiter\Service\EvaluationPipeline;

/**
 * The pipeline-level fixtures of the G5 v2 study (`g5/identity_layer_v2`, PLAN.md section 4.3), ported from
 * `g5/identity_layer/lib/g5.php` (`g5Pipe` and its helpers) onto the package test stores. The expected values are
 * never re-typed: {@see self::archive()} reads them from the archived `expected/` files at test time.
 *
 * The study carried the instance id in the session device id; the fixtures pass it both ways (`sessionDeviceId` and
 * `clientInstanceId`) so that the fingerprint component varies per instance exactly as it did in the archive, while the
 * id component comes from the production input.
 */
final class FamilyFixtures
{
    public const UA_A = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36';
    public const UA_B = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/127.0.0.0 Safari/537.36';

    public const ARCHIVE_DIRECTORY = '/investigations/k2-identity-admission-97/g5/identity_layer_v2/expected/';

    /** @var list<string> */
    public const FIXTURES = [
        'STABLE-600', 'STABLE-300', 'STABLE-60', 'KDL', 'UAC12', 'UAX24', 'AE', 'MIXPRES', 'ROT-300', 'UAROT-300',
        'NOID-STABLE-300', 'NOID-KDL', 'NOID-UAC12', 'NOID-AE',
    ];

    public static function context(string $ua, ?string $inst, bool $known = true): RateLimitContextDTO
    {
        return new RateLimitContextDTO(
            '198.51.100.20',
            $ua,
            'acct-x',
            null,
            $inst,
            false,
            [],
            $known,
            null,
            clientInstanceId: $inst,
        );
    }

    /**
     * Archived rows of one build file, keyed `lane|fixture`, without the identifying columns.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function archive(string $file): array
    {
        $lines = file(dirname(__DIR__, 3) . self::ARCHIVE_DIRECTORY . $file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            throw new \LogicException("Archive file {$file} is unreadable.");
        }
        $rows = [];
        foreach ($lines as $line) {
            $row = self::object(json_decode($line, true, 512, JSON_THROW_ON_ERROR));
            $lane = $row['lane'] ?? null;
            $fixture = $row['fixture'] ?? null;
            if (! is_string($lane) || ! is_string($fixture)) {
                throw new \LogicException('Archive row has no lane or fixture.');
            }
            unset($row['s'], $row['arm'], $row['lane'], $row['fixture']);
            $rows[$lane . '|' . $fixture] = $row;
        }

        return $rows;
    }

    /**
     * @param array<mixed> $row
     * @return array<string, mixed>
     */
    public static function normalize(array $row): array
    {
        return self::object(json_decode(json_encode($row, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR));
    }

    /** @return array<string, mixed> */
    private static function object(mixed $decoded): array
    {
        if (! is_array($decoded)) {
            throw new \LogicException('Value is not an object.');
        }
        $object = [];
        foreach ($decoded as $key => $value) {
            if (! is_string($key)) {
                throw new \LogicException('Object has a non-string key.');
            }
            $object[$key] = $value;
        }

        return $object;
    }

    /**
     * @param class-string<EvaluationPipeline> $pipelineClass
     */
    public static function lab(?AdmissionLaneEnum $lane, string $pipelineClass = EvaluationPipeline::class): FamilyLab
    {
        return new FamilyLab(new OptedLoginPolicy(), $lane, $pipelineClass);
    }

    public static function k5Key(FamilyLab $lab, RateLimitContextDTO $c): string
    {
        $d = $lab->resolver->resolve($c);
        $type = $lab->lane === AdmissionLaneEnum::P ? 'k5p' : 'k5';

        return hash_hmac('sha256', "{$lab->policy->getName()}:rate_limiter:{$type}:v2:prod:{$c->accountId}:" . ($d->deviceIdHash ?? $d->fingerprintHash), $lab->secret);
    }

    public static function k4Key(FamilyLab $lab, RateLimitContextDTO $c): string
    {
        $type = $lab->lane === AdmissionLaneEnum::P ? 'k4p' : 'k4';

        return hash_hmac('sha256', "{$lab->policy->getName()}:rate_limiter:{$type}:v2:prod:{$c->accountId}", $lab->secret);
    }

    public static function allowanceKey(FamilyLab $lab, string $account): string
    {
        $suffix = $lab->lane === AdmissionLaneEnum::P ? '_p' : '';

        return hash_hmac('sha256', "{$lab->policy->getName()}:rate_limiter:aux:known_device_allowance{$suffix}:v1:prod:{$account}", $lab->secret);
    }

    public static function blockLevel(FamilyLab $lab, string $key): ?int
    {
        $b = $lab->store->checkBlock($key);

        return $b !== null && $b->expiresAt > $lab->clock->now()->getTimestamp() ? $b->level : null;
    }

    /** @return array{k5key: string, k5score: ?int, blkK5: ?int, blkK4: ?int, allowKey: string, allowance: ?int} */
    public static function snapshot(FamilyLab $lab, RateLimitContextDTO $c): array
    {
        $k5 = self::k5Key($lab, $c);
        $k4 = self::k4Key($lab, $c);
        $allowance = self::allowanceKey($lab, (string) $c->accountId);

        return [
            'k5key' => substr($k5, 0, 12),
            'k5score' => $lab->store->get($k5)?->value,
            'blkK5' => self::blockLevel($lab, $k5),
            'blkK4' => self::blockLevel($lab, $k4),
            'allowKey' => substr($allowance, 0, 12),
            'allowance' => $lab->store->getBudget($allowance)?->count,
        ];
    }

    /** One attempt as a Host runs it: pre-check, then the failure only if the pre-check allowed. */
    public static function attempt(FamilyLab $lab, RateLimitContextDTO $c, string &$dec, int &$reach): void
    {
        $r = $lab->run($c, 'check');
        $dec .= $r['d'];
        if ($r['d'] !== 'A') {
            return;
        }
        $reach++;
        $r2 = $lab->run($c, 'fail');
        $dec .= $r2['d'] === 'A' ? '' : '!' . $r2['d'];
    }

    /**
     * One fixture on the given lane, field for field the study's `g5Pipe`.
     *
     * @param class-string<EvaluationPipeline> $pipelineClass
     * @return array<string, mixed>
     */
    public static function run(string $fixture, ?AdmissionLaneEnum $lane, string $pipelineClass = EvaluationPipeline::class): array
    {
        $noId = str_starts_with($fixture, 'NOID-');
        $inst = static fn(string $s): ?string => $noId ? null : $s;
        $base = $noId ? substr($fixture, 5) : $fixture;
        $lab = self::lab($lane, $pipelineClass);
        $reach = 0;
        $dec = '';
        if (in_array($base, ['STABLE-600', 'STABLE-300', 'STABLE-60'], true)) {
            $iv = (int) substr($base, 7);
            $maxK4 = 0;
            for ($t = 0; $t < 86400; $t += $iv) {
                $lab->at($t);
                $c = self::context(self::UA_A, $inst('i:kd'));
                self::attempt($lab, $c, $dec, $reach);
                $maxK4 = max($maxK4, $lab->store->get(self::k4Key($lab, $c))->value ?? 0);
            }

            return ['reach' => $reach, 'maxK4score' => $maxK4];
        }

        if (in_array($base, ['KDL', 'UAC12'], true)) {
            $c1 = self::context(self::UA_A, $inst('i:kd'));
            $c2 = self::context($base === 'UAC12' ? self::UA_B : self::UA_A, $inst('i:kd'));
            $maxK5 = $maxK4 = 0;
            for ($i = 0; $i < 12; $i++) {
                $lab->at($i * 60);
                $cur = $i >= 6 ? $c2 : $c1;
                self::attempt($lab, $cur, $dec, $reach);
                $snap = self::snapshot($lab, $cur);
                $maxK5 = max($maxK5, $snap['blkK5'] ?? 0);
                $maxK4 = max($maxK4, $snap['blkK4'] ?? 0);
            }
            $other = $lab->run(self::context(self::UA_A, $inst('i:other')), 'check')['d'];

            return [
                'dec' => $dec, 'reach' => $reach, 'maxK5Level' => $maxK5, 'maxK4Level' => $maxK4, 'secondDevice' => $other,
                'before' => self::snapshot($lab, $c1), 'after' => self::snapshot($lab, $c2),
                'k5SameKey' => self::k5Key($lab, $c1) === self::k5Key($lab, $c2),
            ];
        }

        if ($base === 'UAX24') {
            $out = [];
            foreach (['flip' => true, 'ctl' => false] as $k => $flip) {
                $lab = self::lab($lane, $pipelineClass);
                $reach = 0;
                $dec = '';
                for ($t = 0; $t < 86400; $t += 60) {
                    $lab->at($t);
                    self::attempt($lab, self::context($flip && $t >= 43200 ? self::UA_B : self::UA_A, $inst('i:kd')), $dec, $reach);
                }
                $out[$k] = $reach;
            }

            return ['flip' => $out['flip'], 'ctl' => $out['ctl']];
        }

        if ($base === 'AE') {
            $c = self::context(self::UA_A, $inst('i:kd'));
            $lab->at(0);
            for ($i = 0; $i < 4; $i++) {
                self::attempt($lab, $c, $dec, $reach);
            }
            for ($i = 0; $i < 3; $i++) {
                $dec .= $lab->run($c, 'check')['d'];
            }
            $lab->at(300);
            $r = $lab->run($c, 'check');
            $dec .= '|' . $r['d'];
            if ($r['d'] === 'A') {
                $f = $lab->run($c, 'fail');
                $dec .= 'f' . $f['d'] . $f['lvl'];
            }
            $same = $lab->run($c, 'check')['d'];
            $second = $lab->run(self::context(self::UA_A, $inst('i:other')), 'check')['d'];

            return ['dec' => $dec, 'sameDevice' => $same, 'secondDevice' => $second, 'snap' => self::snapshot($lab, $c)];
        }

        if ($base === 'MIXPRES') {
            $out = [];
            foreach (['both' => true, 'ctl' => false] as $k => $withB) {
                $lab = self::lab($lane, $pipelineClass);
                $a = $unused = '';
                $n = 0;
                if ($withB) {
                    foreach ([0, 30, 60, 90] as $t) {
                        $lab->at($t);
                        self::attempt($lab, self::context(self::UA_B, null), $unused, $n);
                    }
                }
                foreach ([120, 150] as $t) {
                    $lab->at($t);
                    self::attempt($lab, self::context(self::UA_A, 'i:a'), $a, $n);
                }
                $out[$k] = $a;
            }
            $keyA = substr(self::k5Key($lab, self::context(self::UA_A, 'i:a')), 0, 12);
            $keyB = substr(self::k5Key($lab, self::context(self::UA_B, null)), 0, 12);

            return [
                'both' => $out['both'], 'ctl' => $out['ctl'], 'k5KeyA' => $keyA, 'k5KeyB' => $keyB,
                'keysDiffer' => $keyA !== $keyB, 'aIdentical' => $out['both'] === $out['ctl'],
            ];
        }

        if ($base === 'ROT-300') {
            for ($t = 0; $t < 86400; $t += 300) {
                $lab->at($t);
                self::attempt($lab, self::context(self::UA_A, $inst('i:r' . $t)), $dec, $reach);
            }

            return ['reach' => $reach];
        }

        if ($base === 'UAROT-300') {
            for ($t = 0; $t < 86400; $t += 300) {
                $lab->at($t);
                self::attempt($lab, self::context(intdiv($t, 300) % 2 === 1 ? self::UA_A : self::UA_B, $inst('i:kd')), $dec, $reach);
            }

            return ['reach' => $reach];

        }

        throw new \LogicException("unknown pipeline fixture {$fixture}");
    }
}
