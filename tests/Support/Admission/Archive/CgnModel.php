<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\Support\Admission\Archive;

use Maatify\RateLimiter\DTO\AdmissionCredentialDTO;
use Maatify\RateLimiter\DTO\DeviceIdentityDTO;
use Maatify\RateLimiter\DTO\RateLimitContextDTO;
use Maatify\RateLimiter\DTO\RateLimitResultDTO;
use Maatify\RateLimiter\Enum\AdmissionLaneEnum;
use Maatify\RateLimiter\Service\AdmissionRuntime;
use Maatify\RateLimiter\Tests\Support\Admission\EngineLab;

/**
 * The CGN-B admission-layer MODEL (LT-23 condition 4: reported as in the archive, not a gate) through the production
 * `AdmissionRuntime`: `g4/integrated_v2/scenarios/v2_cgn.php`, the FULL2 arm, transcribed. As in the archive there is no pipeline:
 * N clients behind one source, each with its own account, λ logins per client per day over 3 days with a 09:00 peak, 5 % typos
 * retried 30 s later; a client is bound (a credential, the P door) after its first verified success; a sprayer on day 2. The
 * event generator is the archive's (`g4v2Poisson`, `g4v2Hour`, the seeds and the sort of v2_cgn.php:22-79), run in this process.
 * The lane is the runtime's (`resolveLane` on the credential the Host model mints); the reservation and the outcome are the
 * runtime's (`reserve`, `applyOutcome` with the permit).
 */
final class CgnModel
{
    /** v2_cgn.php:22-34 */
    private static function poisson(float $mean): int
    {
        $l = exp(-$mean);
        $k = 0;
        $p = 1.0;
        do {
            $k++;
            $p *= mt_rand() / mt_getrandmax();
        } while ($p > $l);

        return $k - 1;
    }

    /** v2_cgn.php:36-45 */
    private static function hour(): int
    {
        $x = mt_rand(0, 23 * 1000 - 1);
        if ($x < 3000) {
            return 9;
        }
        $h = intdiv($x - 3000, 1000);

        return $h >= 9 ? $h + 1 : $h;
    }

    /**
     * One archived `cgn` row (v2_cgn.php:47-121) for the FULL2 budgets.
     *
     * @return array{days: list<array<string, int|float>>, sprayAdmitted: int, boundAtEnd: int}
     */
    public static function run(int $n, int $lambda, bool $spray): array
    {
        $fields = ['attempts', 'refused', 'boundAttempts', 'boundRefused', 'unboundRefused'];
        /** @var array<string, array{0: int, 1: int, 2: int}> $count field => per-day count */
        $count = array_fill_keys($fields, [0, 0, 0]);
        $sprayAdmitted = 0;
        $boundAtEnd = 0;
        for ($rep = 1; $rep <= 4; $rep++) {
            mt_srand($rep * 1000003 + $n * 31 + $lambda * 7);
            $events = [];
            for ($d = 0; $d < 3; $d++) {
                for ($i = 0; $i < $n; $i++) {
                    for ($j = 0, $k = self::poisson((float) $lambda); $j < $k; $j++) {
                        $t = $d * 86400 + self::hour() * 3600 + mt_rand(0, 3599);
                        if (mt_rand(1, 100) <= 5) {
                            $events[] = [$t, 1, $i, 'fail'];
                            $t += 30;
                        }
                        $events[] = [$t, 1, $i, 'ok'];
                    }
                }
            }
            if ($spray) {
                for ($t = 86400; $t < 172800; $t++) {
                    $events[] = [$t, 0, $t, 'fail'];
                }
            }
            usort($events, static fn(array $a, array $b): int => [$a[0], $a[1], $a[2]] <=> [$b[0], $b[1], $b[2]]);
            $lab = new EngineLab(StreamReplay::fullBudgets());
            /** @var AdmissionRuntime $runtime */
            $runtime = $lab->runtime;
            $bound = [];
            $credentials = [];
            foreach ($events as [$t, $legit, $who, $outcome]) {
                $lab->at($t);
                $acct = $legit === 1 ? "c$who" : "x$who";
                $p = $legit === 1 && isset($bound[$who]);
                $credential = null;
                if ($p) {
                    $credentials[$who] ??= $lab->codec->encode(new AdmissionCredentialDTO($acct, [EngineLab::GROUP], "i-$who", "tok-$who", EngineLab::T0 + 4 * 86400, $lab->now(), 0));
                    $credential = $credentials[$who];
                }
                $context = new RateLimitContextDTO(
                    '100.64.0.1',
                    'cgn',
                    $acct,
                    null,
                    null,
                    false,
                    [],
                    false,
                    null,
                    clientInstanceId: $p ? "i-$who" : null,
                    admissionCredential: $credential,
                );
                $lane = $runtime->resolveLane(EngineLab::GROUP, $context);
                if ($p && $lane->lane !== AdmissionLaneEnum::P) {
                    throw new \LogicException("a bound client was not verified at t={$t}");
                }
                $device = new DeviceIdentityDTO($legit === 1 ? "dev-$who" : null, 'HIGH', false);
                $allowed = new RateLimitResultDTO(RateLimitResultDTO::DECISION_ALLOW, 0, 0, 'NORMAL');
                $result = $runtime->reserve(EngineLab::POLICY, EngineLab::GROUP, $context, $device, $lane, $allowed);
                $ok = $result->decision === RateLimitResultDTO::DECISION_ALLOW;
                if ($ok) {
                    $runtime->applyOutcome(EngineLab::POLICY, EngineLab::GROUP, $context, (string) EngineLab::permit($result), $outcome === 'ok');
                }
                if ($legit === 0) {
                    $sprayAdmitted += $ok ? 1 : 0;
                    continue;
                }
                $d = intdiv($t, 86400);
                $count['attempts'][$d]++;
                $count['refused'][$d] += $ok ? 0 : 1;
                $count['boundAttempts'][$d] += $p ? 1 : 0;
                $count['boundRefused'][$d] += $p && ! $ok ? 1 : 0;
                $count['unboundRefused'][$d] += ! $p && ! $ok ? 1 : 0;
                if ($ok && $outcome === 'ok') {
                    $bound[$who] = true;
                }
            }
            $boundAtEnd += count($bound);
            unset($lab, $runtime);
            gc_collect_cycles();
        }
        $days = [];
        for ($d = 0; $d < 3; $d++) {
            $day = [];
            foreach ($fields as $field) {
                $day[$field] = $count[$field][$d];
            }
            $day['refusedShare'] = $day['attempts'] === 0 ? 0.0 : round($day['refused'] / $day['attempts'], 6);
            $days[] = $day;
        }

        return ['days' => $days, 'sprayAdmitted' => $sprayAdmitted, 'boundAtEnd' => $boundAtEnd];
    }
}
