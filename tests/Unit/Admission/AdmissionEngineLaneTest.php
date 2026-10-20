<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\Unit\Admission;

use Maatify\RateLimiter\DTO\RateLimitResultDTO;
use Maatify\RateLimiter\Service\AdmissionCredentialCodec;
use Maatify\RateLimiter\Tests\Support\Admission\EngineLab;
use Maatify\RateLimiter\Tests\Support\Admission\ServiceMutant;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Gate LANE (contract §8.3, plan §5.2): U never writes P state and P is entered only through a verified credential. The lane
 * is observed through the door that refuses: with `U_src` 1, a second admission from one source is refused on the U door
 * (`source_capacity`) and admitted on the P door (which does not use the source scope).
 */
#[Group('lane')]
final class AdmissionEngineLaneTest extends TestCase
{
    private const IP = '198.51.100.7';

    /**
     * True when the request travelled the P door (a second admission from an exhausted source is admitted).
     *
     * @param \Closure(EngineLab): \Maatify\RateLimiter\DTO\RateLimitContextDTO $contextFor
     */
    private function reachesPDoor(EngineLab $lab, \Closure $contextFor): bool
    {
        $first = $lab->check($contextFor($lab));
        self::assertSame(RateLimitResultDTO::DECISION_ALLOW, $first->decision, 'the first admission of a source is always admitted');
        $second = $lab->check($contextFor($lab));

        return $second->decision === RateLimitResultDTO::DECISION_ALLOW && EngineLab::cause($second) !== 'source_capacity';
    }

    private function lab(): EngineLab
    {
        return new EngineLab(EngineLab::budgets(sourceCapacity: 1));
    }

    public function testAVerifiedCredentialSelectsTheProvenDoor(): void
    {
        $lab = $this->lab();
        $credential = $lab->mint('acct-1', 'inst-1');

        self::assertTrue($this->reachesPDoor($lab, static fn(EngineLab $l) => $l->context('acct-1', self::IP, 'inst-1', $credential)));
    }

    /** @return iterable<string, array{string}> */
    public static function unprovenCases(): iterable
    {
        yield 'wrong account' => ['wrong-account'];
        yield 'wrong budget group' => ['wrong-group'];
        yield 'wrong instance' => ['wrong-instance'];
        yield 'expired' => ['expired'];
        yield 'stale epoch' => ['stale-epoch'];
        yield 'tampered MAC' => ['tampered'];
        yield 'credential of another binding key' => ['foreign-key'];
        yield 'no credential at all' => ['none'];
    }

    #[DataProvider('unprovenCases')]
    public function testEveryFailedCheckIsTheUnprovenDoorAndNeverARefusalOrAnException(string $case): void
    {
        $lab = $this->lab();
        $credential = match ($case) {
            'wrong-account' => $lab->mint('acct-other', 'inst-1'),
            'wrong-group' => $lab->mint('acct-1', 'inst-1', group: 'otp'),
            'wrong-instance' => $lab->mint('acct-1', 'inst-other'),
            'expired' => $lab->mint('acct-1', 'inst-1', lifetime: 1),
            'stale-epoch' => $lab->mint('acct-1', 'inst-1', epoch: 0),
            'tampered' => substr($lab->mint('acct-1', 'inst-1'), 0, -2) . 'xx',
            'foreign-key' => $lab->mint('acct-1', 'inst-1', codec: new AdmissionCredentialCodec('another-binding-key')),
            default => null,
        };
        if ($case === 'expired') {
            $lab->at(10);
        }
        if ($case === 'stale-epoch') {
            $lab->engine->revokeAccountBinding('acct-1');
        }

        self::assertFalse($this->reachesPDoor($lab, static fn(EngineLab $l) => $l->context('acct-1', self::IP, 'inst-1', $credential)));
    }

    public function testNoFlagHeaderOrInstanceIdSelectsTheProvenDoor(): void
    {
        foreach (['known device' => [true, false], 'trusted session' => [false, true]] as $label => [$known, $trusted]) {
            $lab = $this->lab();
            self::assertFalse(
                $this->reachesPDoor($lab, static fn(EngineLab $l) => $l->context('acct-1', self::IP, 'inst-1', null, 'd1', $known, $trusted)),
                $label . ' must not select P',
            );
        }
        $lab = $this->lab();
        self::assertFalse($this->reachesPDoor($lab, static fn(EngineLab $l) => $l->context('acct-1', self::IP, 'inst-1')), 'an instance id alone must not select P');
    }

    public function testDetectorALaneThatLetsIsKnownForAccountSelectPFailsTheGate(): void
    {
        $class = ServiceMutant::load('AdmissionRuntime', [
            'laneFromVerdict' => 'return ($verdict->isValid() || $context->isDevicePreviouslyVerifiedForAccount) ? \Maatify\RateLimiter\Enum\AdmissionLaneEnum::P : \Maatify\RateLimiter\Enum\AdmissionLaneEnum::U;',
        ]);
        $lab = new EngineLab(EngineLab::budgets(sourceCapacity: 1), runtimeClass: $class);

        // The request reaches the credential check (a credential that does not verify) while the Host says "known device".
        $unverified = $lab->mint('acct-other', 'inst-1');
        self::assertTrue(
            $this->reachesPDoor($lab, static fn(EngineLab $l) => $l->context('acct-1', self::IP, 'inst-1', $unverified, 'd1', true)),
            'DETECTOR: the mutated lane must put a known-device request on the P door, which the gate above forbids',
        );
    }

    public function testAnUnprovenRequestWritesNoProvenState(): void
    {
        $lab = $this->lab();
        $lab->check($lab->context('acct-1', self::IP, 'inst-1', $lab->mint('acct-other', 'inst-1')));
        $lab->check($lab->context('acct-1', self::IP, 'inst-1', null));

        // The P counters of the account are untouched: the P door still has its full capacity for a proven request.
        $proven = $lab->mint('acct-1', 'inst-1');
        for ($i = 0; $i < 3; $i++) {
            self::assertSame(RateLimitResultDTO::DECISION_ALLOW, $lab->check($lab->context('acct-1', self::IP, 'inst-1', $proven))->decision);
        }
    }
}
