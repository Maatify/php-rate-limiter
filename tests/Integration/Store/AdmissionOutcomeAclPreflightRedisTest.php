<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\Integration\Store;

use Maatify\RateLimiter\DTO\AdmissionOutcomeDTO;
use Maatify\RateLimiter\DTO\AdmissionScopeDTO;
use Maatify\RateLimiter\Enum\AdmissionOutcomeKindEnum;
use Maatify\RateLimiter\Enum\AdmissionPermitClaimEnum;
use Maatify\RateLimiter\Exception\RateLimiterException;
use Maatify\RateLimiter\Repository\Redis\RedisFullCapabilityStore;
use Maatify\RateLimiter\Tests\Support\Admission\AdmissionScenarioBuilder as S;
use Maatify\RateLimiter\Tests\Support\Redis\RespRedisCommandExecutor;
use Maatify\RateLimiter\Tests\Support\Redis\StateSnapshot;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * #282 (DEC-036, DEC-042, DEC-018): the direct outcome settlement (`claimAdmissionPermitWithOutcome`) under a Redis ACL account that may not
 * run one of the write commands the chosen plan issues. The settlement checks the actual write plan with `redis.acl_check_cmd` inside the
 * script, before its first write: a refusal leaves the permit `ISSUED` and every key, value and absolute expiry (`StateSnapshot`: byte-exact `DUMP` and exact `PEXPIRETIME`) as it was.
 *
 * Every case has its own temporary account (removed afterwards); the data is synthetic.
 */
#[Group('atomic-claim')]
#[Group('admission-outcome-acl-282')]
#[Group('php32')]
final class AdmissionOutcomeAclPreflightRedisTest extends AdmissionRedisGateTestCase
{
    private const NOW = S::NOW;

    private const PASSWORD = 'synthetic-282-password';

    /** @var list<string> */
    private array $users = [];

    protected function tearDown(): void
    {
        if (isset($this->executor)) {
            foreach ($this->users as $user) {
                $this->executor->execute(['ACL', 'DELUSER', $user]);
            }
            $this->executor->execute(['FLUSHDB']);
        }
    }

    /** @return iterable<string, array{0: string, 1: AdmissionOutcomeKindEnum, 2: string}> case, outcome kind, denied command */
    public static function refusals(): iterable
    {
        yield 'failure: ZREMRANGEBYSCORE refused' => ['failure', AdmissionOutcomeKindEnum::FAILURE, 'ZREMRANGEBYSCORE'];
        yield 'failure: ZADD refused' => ['failure', AdmissionOutcomeKindEnum::FAILURE, 'ZADD'];
        yield 'failure: EXPIRE refused' => ['failure', AdmissionOutcomeKindEnum::FAILURE, 'EXPIRE'];
        yield 'failure: HSET (the permit write) refused' => ['failure', AdmissionOutcomeKindEnum::FAILURE, 'HSET'];
        yield 'success: ZREM refused' => ['success', AdmissionOutcomeKindEnum::SUCCESS, 'ZREM'];
        yield 'success: DEL (bucket refund) refused' => ['bucket', AdmissionOutcomeKindEnum::SUCCESS, 'DEL'];
    }

    #[DataProvider('refusals')]
    public function testARefusedWriteCommandLeavesThePermitKeysAndAbsoluteExpiriesUnchanged(string $case, AdmissionOutcomeKindEnum $kind, string $denied): void
    {
        [$scopes, $permitId] = $this->arrange($case);
        $outcome = new AdmissionOutcomeDTO(S::CLASS_NAME, $kind, $scopes, $permitId);
        $before = $this->observe();
        self::assertSame('ISSUED', $this->executor->execute(['HGET', $this->keys->permitKey($permitId), 'state']));

        $restricted = $this->storeOf($this->account('deny-' . strtolower($denied), [$denied]));
        // A BackendFailureException would escape this catch and fail the test: a permission refusal is a server error reply (DEC-018).
        $message = null;
        try {
            $restricted->claimAdmissionPermitWithOutcome('U', 'acctT', 'login', $outcome, self::NOW + 1);
        } catch (RateLimiterException $exception) {
            $message = $exception->getMessage();
        }

        // State first: it is the part of the contract a refusal after a write breaks.
        self::assertSame($before, $this->observe(), 'permit, keys, values and absolute expiries are as before the refusal');
        self::assertSame('ISSUED', $this->executor->execute(['HGET', $this->keys->permitKey($permitId), 'state']));
        self::assertNotNull($message, 'A refused write command must raise an error.');
        self::assertStringContainsString('NOPERM', $message);
        self::assertStringContainsString(strtolower($denied), strtolower($message), 'the message names the refused command');
        self::assertStringNotContainsString($permitId, $message);
        self::assertStringNotContainsString('maatify:rate-limiter', $message);
        self::assertStringNotContainsString('acct-', $message);

        // The permit survived: the same outcome settles once the account is whole (the repeat guard is intact).
        self::assertSame(AdmissionPermitClaimEnum::ACCEPTED, $this->store->claimAdmissionPermitWithOutcome('U', 'acctT', 'login', $outcome, self::NOW + 2));
        self::assertSame('CLAIMED', $this->executor->execute(['HGET', $this->keys->permitKey($permitId), 'state']));
        $settled = $this->observe();
        self::assertSame(AdmissionPermitClaimEnum::DUPLICATE, $this->store->claimAdmissionPermitWithOutcome('U', 'acctT', 'login', $outcome, self::NOW + 3));
        self::assertSame($settled, $this->observe(), 'a repeat changes nothing');
    }

    /** @return iterable<string, array{0: string, 1: AdmissionOutcomeKindEnum}> */
    public static function successes(): iterable
    {
        yield 'failure' => ['failure', AdmissionOutcomeKindEnum::FAILURE];
        yield 'success set' => ['success', AdmissionOutcomeKindEnum::SUCCESS];
        yield 'success bucket' => ['bucket', AdmissionOutcomeKindEnum::SUCCESS];
    }

    #[DataProvider('successes')]
    public function testAnAccountThatMayRunThePlanSettlesLikeBeforeAndARepeatIsADuplicate(string $case, AdmissionOutcomeKindEnum $kind): void
    {
        [$scopes, $permitId] = $this->arrange($case);
        $outcome = new AdmissionOutcomeDTO(S::CLASS_NAME, $kind, $scopes, $permitId);
        $account = $this->storeOf($this->account('whole-' . $case, []));

        self::assertSame(AdmissionPermitClaimEnum::ACCEPTED, $account->claimAdmissionPermitWithOutcome('U', 'acctT', 'login', $outcome, self::NOW + 1));
        self::assertSame('CLAIMED', $this->executor->execute(['HGET', $this->keys->permitKey($permitId), 'state']));
        $settled = $this->observe();
        self::assertSame(AdmissionPermitClaimEnum::DUPLICATE, $account->claimAdmissionPermitWithOutcome('U', 'acctT', 'login', $outcome, self::NOW + 2));
        self::assertSame($settled, $this->observe());
    }

    public function testTheSnapshotSeesAnExpiryOnlyChangeOfOneMillisecond(): void
    {
        // Control of the comparison itself: on PHP_INT_SIZE 4 an int cast of PEXPIRETIME saturates and hides a 1 ms move; the snapshot keeps the exact text.
        [, $permitId] = $this->arrange('failure');
        $permitKey = $this->keys->permitKey($permitId);
        $before = $this->observe();
        self::assertSame($before, $this->observe());
        StateSnapshot::perturbExpiryByOneMillisecond($this->executor, $permitKey);
        $after = $this->observe();
        self::assertNotSame($before, $after);
        self::assertSame([$permitKey], array_keys(array_diff_assoc($after, $before)), 'only the perturbed key differs, by its expiry alone');
        self::assertSame(
            explode('@', $before[$permitKey])[0],
            explode('@', $after[$permitKey])[0],
            'the bytes of the key are identical; only the expiry moved',
        );
    }

    public function testAnAccountThatLacksAnUnusedCommandStillSettles(): void
    {
        // Only the commands of the chosen plan are checked: a failure outcome never runs ZREM, DEL or SADD.
        [$scopes, $permitId] = $this->arrange('failure');
        $outcome = new AdmissionOutcomeDTO(S::CLASS_NAME, AdmissionOutcomeKindEnum::FAILURE, $scopes, $permitId);
        $account = $this->storeOf($this->account('unused', ['ZREM', 'DEL', 'SADD']));

        self::assertSame(AdmissionPermitClaimEnum::ACCEPTED, $account->claimAdmissionPermitWithOutcome('U', 'acctT', 'login', $outcome, self::NOW + 1));
    }

    /** @return array{0: list<AdmissionScopeDTO>, 1: string} the outcome scopes and the id of an ISSUED permit that reserved them */
    private function arrange(string $case): array
    {
        $permitId = 'p-282-' . str_replace(' ', '-', $case);
        if ($case === 'failure') {
            $reserved = [S::counter('src', 'c:282', 10)];
            $scopes = [S::setSize('ufail', 's:282-fail', 4, 'acct-282')];
        } elseif ($case === 'success') {
            $reserved = [S::setSize('uset', 's:282-set', 4, 'acct-282')];
            $scopes = $reserved;
        } else {
            $reserved = [S::bucket('tok', 'b:282', 3, 60)];
            $scopes = $reserved;
        }
        $reservation = $this->store->reserveAdmission(S::withPermit(S::permit($permitId, self::NOW + 30), ...$reserved), self::NOW);
        self::assertTrue($reservation->admitted);

        return [$scopes, $permitId];
    }

    /** @return array<string, string> every key's byte-exact DUMP and exact PEXPIRETIME (text, so no 32-bit saturation), by key */
    private function observe(): array
    {
        return StateSnapshot::all($this->executor);
    }

    /** @param list<string> $denied */
    private function account(string $name, array $denied): RespRedisCommandExecutor
    {
        $user = 'acl282-' . $name;
        $rules = array_map(static fn(string $command): string => '-' . strtolower($command), $denied);
        $this->executor->execute(['ACL', 'SETUSER', $user, 'reset', 'on', '>' . self::PASSWORD, '~*', '+@all', ...$rules]);
        $this->users[] = $user;
        $connection = new RespRedisCommandExecutor($this->host, $this->port, RespRedisCommandExecutor::MODE_EVAL);
        self::assertSame('OK', $connection->execute(['AUTH', $user, self::PASSWORD]));

        return $connection;
    }

    private function storeOf(RespRedisCommandExecutor $connection): RedisFullCapabilityStore
    {
        return new RedisFullCapabilityStore($connection, self::NAMESPACE);
    }
}
