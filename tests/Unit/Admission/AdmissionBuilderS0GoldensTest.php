<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\Unit\Admission;

use Maatify\RateLimiter\Builder\RateLimiterBuilder;
use Maatify\RateLimiter\Command\RateLimitCommand;
use Maatify\RateLimiter\Config\ApiHeavyProtectionPolicy;
use Maatify\RateLimiter\Config\BlockPolicyInterface;
use Maatify\RateLimiter\Config\LoginProtectionPolicy;
use Maatify\RateLimiter\Config\OtpProtectionPolicy;
use Maatify\RateLimiter\Config\RateLimiterConfig;
use Maatify\RateLimiter\DTO\AdmissionCredentialDTO;
use Maatify\RateLimiter\DTO\RateLimitContextDTO;
use Maatify\RateLimiter\DTO\RateLimitResultDTO;
use Maatify\RateLimiter\Service\AdmissionCredentialCodec;
use Maatify\RateLimiter\Tests\Support\Admission\DeclaresCapabilityWithoutInterfacePolicy;
use Maatify\RateLimiter\Tests\Support\Admission\EngineLab;
use Maatify\RateLimiter\Tests\Support\Clock\FixedClock;
use Maatify\RateLimiter\Tests\Support\FailureSignal\RecordingFailureSignalEmitter;
use Maatify\RateLimiter\Tests\Support\FullCapability\FullCapabilityInMemoryStore;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Gate S0-PARITY of stage 5-c through the BUILDER (5c/PLAN.md §7.6.1): every policy that does not opt in, composed by
 * `RateLimiterBuilder::build()` with and without a binding key, gives the goldens below. They were recorded on the unmodified
 * `main@13870bb` (this test was committed before any change to `src/` in 5-c) and must hold unchanged at the atomic HEAD.
 *
 * Scenario A is the 160-step scenario of `FamilyS0ParityTest` / `AdmissionEngineS0GoldensTest` (the Host sends an instance id
 * and a minted credential). Scenario B drives the collective rules (K1 spray, K2 churn and dilution) from one source, which
 * scenario A does not reach.
 */
#[Group('s0')]
final class AdmissionBuilderS0GoldensTest extends TestCase
{
    /** Recorded on `main@13870bb`: policy => scenario => decisions and the sha-256 of the masked store state. */
    private const GOLDEN = [
        'login_protection' => [
            'A' => [
                'decisions' => 'AAAAAAAAAAHAAHAHHAAAAAHAHAHHAAHHHAAASHAAHHAHHHAHHAAHAAASAAHSAHHSHHSAAASAHHHAHAHHHHSHSSHSSSHHSHAHHSAHASHSASHHHAHHHAHAHHSAAHHAHHHHHHHHHAAHHHAAHHHAAAHHHAAHHAAAHHHA',
                'state' => 'cdf7bbe0d10eaffe6f657a51c5de2e83063b81023b5a6f0bfa8173b16fd0e8a2',
            ],
            'B' => [
                'decisions' => 'AAAAAAAAHHHSHHHSHHHHHHHHHHHHHHHHASAHASHHHHHHHHHHHHHHHHHHHHHH',
                'state' => '2a4f5bea37e8e9c58c2cccf7069cf0c152bf2e65022c5ec8ace83a69065f4650',
            ],
        ],
        'otp_protection' => [
            'A' => [
                'decisions' => 'AAAAAASAAAHASHSHHASASSHAHSHHSSHHHSSHHHSAHHHHHHHHHAAHHSAAHHHAAHHAHHHHAAAHHHAAHHHAHHHHAAHHAAAHHHSAHHAHAHHHAAHHAAAHHAHAHHHAAHHAHAHHHHAHHAAAHHAHAHHHAAHHAAAHHHAAHHHH',
                'state' => '0dfb7076745d9f41c6fb55a9c79c483fc11d28af5b1595a86f8002895fce7339',
            ],
            'B' => [
                'decisions' => 'ASASASASHHHHHHHHHHHHHHHHHHHHHHHHAHAHAHHHHHHHHHHHHHHHHHHHHHHH',
                'state' => 'ce572295d752faaca26805a7eedb42f39c6206fc1a229b0bd031e5665567577d',
            ],
        ],
        'api_heavy_protection' => [
            'A' => [
                'decisions' => 'AAAAAAAAAAHAAHAHHAAAAAHAHAHHAAHHHAAAAHAAHHAHHHAHHAAHAAAAAAHAAHHAHHAAAAAAHHHAHAHHHHAHAAAAAAHAAHAHHAAHAAAAAAHAHAHHAAHAHAAAAHAAHHAHHHAHHAAHAAAAAAHAAHHAHHAAAAAAHHHA',
                'state' => '1896fae147e3e9c2c35b6dddb07606eb3e2f16b3f29231991da3e73bfcc12e82',
            ],
            'B' => [
                'decisions' => 'AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA',
                'state' => '52233da485313f9f7f025b877f2427c2fbae86ff89b6cccddf8d6489c37e23e6',
            ],
        ],
        'custom_auth' => [
            'A' => [
                'decisions' => 'AAAAAAAAAAHAAHAHHAAAAAHAHAHHAAHHHAAASHAAHHAHHHAHHAAHAAASAAHSAHHSHHSAAASAHHHAHAHHHHSHSSHSSSHHSHAHHSAHHSHSHSHHHAHHHAHHHHSHSHHHHHHHHHHHHSHHHHHHHHHHHHHHHHHHHHHHHHHH',
                'state' => '2a65250b3efd987b658802b84423f13eabfc26f73df04e545a85c4660a9be75a',
            ],
            'B' => [
                'decisions' => 'AAAAAAAAASASASASAHAHAHAHAHAHAHAHAHAHAHAHSHSHSHSHAHAHAHAHAHAH',
                'state' => '1f770a7629283e67fee03f49275bf405b6c50449f3f86bb2a5608620b18413b3',
            ],
        ],
    ];

    /** @return array<string, BlockPolicyInterface> */
    private static function policies(): array
    {
        return [
            'login_protection' => new LoginProtectionPolicy(),
            'otp_protection' => new OtpProtectionPolicy(),
            'api_heavy_protection' => new ApiHeavyProtectionPolicy(),
            'custom_auth' => new DeclaresCapabilityWithoutInterfacePolicy('custom_auth'),
        ];
    }

    /** @return iterable<string, array{string, 'A'|'B', bool}> */
    public static function cases(): iterable
    {
        foreach (array_keys(self::policies()) as $policy) {
            foreach (['A', 'B'] as $scenario) {
                yield "$policy, scenario $scenario, no binding key" => [$policy, $scenario, false];
                yield "$policy, scenario $scenario, binding key" => [$policy, $scenario, true];
            }
        }
    }

    /**
     * @param 'A'|'B' $scenario
     * @return array{decisions: string, state: string}
     */
    public static function play(string $policyName, string $scenario, bool $withBindingKey): array
    {
        $policy = self::policies()[$policyName];
        $clock = new FixedClock('2026-01-01 12:00:00');
        $store = new FullCapabilityInMemoryStore($clock);
        $config = new RateLimiterConfig(
            'lab-secret',
            'lab-fp-secret',
            'prod',
            bindingKeySecret: $withBindingKey ? EngineLab::BINDING_SECRET : null,
        );
        $runtime = RateLimiterBuilder::fromFullCapabilityStore($config, $store, new RecordingFailureSignalEmitter())
            ->withClock($clock)
            ->withPolicy($policy)
            ->build();

        $decisions = '';
        foreach ($scenario === 'A' ? self::scenarioA($clock, $policyName) : self::scenarioB($clock, $policyName) as $step) {
            [$context, $command, $advance] = $step;
            $result = $runtime->limit($context, $command);
            $decisions .= $result->decision === RateLimitResultDTO::DECISION_ALLOW
                ? 'A'
                : ($result->decision === RateLimitResultDTO::DECISION_HARD_BLOCK ? 'H' : 'S');
            $clock->setNow($clock->now()->modify($advance));
        }
        $state = preg_replace('/s:32:"[0-9a-f]{32}";/', 's:32:"ID";', serialize($store));

        return ['decisions' => $decisions, 'state' => hash('sha256', (string) $state)];
    }

    /** @return \Generator<int, array{RateLimitContextDTO, RateLimitCommand, string}> */
    private static function scenarioA(FixedClock $clock, string $name): \Generator
    {
        $codec = new AdmissionCredentialCodec(EngineLab::BINDING_SECRET);
        for ($step = 0; $step < 160; $step++) {
            $n = $step % 4;
            $known = $step % 3 !== 0;
            $instance = $known ? 'inst-' . $n : ($step % 5 === 0 ? null : 'new-' . $step);
            $credential = $instance === null ? null : $codec->encode(new AdmissionCredentialDTO(
                'acct-' . $n,
                ['login'],
                $instance,
                'jti-' . $n,
                $clock->now()->getTimestamp() + 86400,
                $clock->now()->getTimestamp(),
                0,
            ));
            $context = new RateLimitContextDTO(
                '198.51.100.' . (10 + $n),
                $step % 7 === 0
                    ? 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.2 Safari/605.1.15'
                    : 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36',
                'acct-' . $n,
                null,
                $instance,
                false,
                [],
                $known,
                null,
                clientInstanceId: $instance,
                admissionCredential: $credential,
            );
            $command = match ($step % 5) {
                0, 3 => RateLimitCommand::checkOnly($name),
                1, 2 => RateLimitCommand::recordFailure($name),
                default => RateLimitCommand::recordSuccess($name),
            };
            yield [$context, $command, '+45 seconds'];
        }
    }

    /** @return \Generator<int, array{RateLimitContextDTO, RateLimitCommand, string}> */
    private static function scenarioB(FixedClock $clock, string $name): \Generator
    {
        for ($step = 0; $step < 60; $step++) {
            $context = new RateLimitContextDTO(
                '203.0.113.7',
                'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/' . (100 + $step) . '.0.0.0 Safari/537.36',
                'acct-' . ($step % 8),
            );
            $command = $step % 2 === 0 ? RateLimitCommand::checkOnly($name) : RateLimitCommand::recordFailure($name);
            yield [$context, $command, '+20 seconds'];
        }
    }

    /** @param 'A'|'B' $scenario */
    #[DataProvider('cases')]
    public function testTheBuilderCompositionReproducesTheGoldenRecordedOnTheBase(string $policy, string $scenario, bool $withBindingKey): void
    {
        self::assertArrayHasKey($policy, self::GOLDEN);
        self::assertSame(self::GOLDEN[$policy][$scenario], self::play($policy, $scenario, $withBindingKey));
    }
}
