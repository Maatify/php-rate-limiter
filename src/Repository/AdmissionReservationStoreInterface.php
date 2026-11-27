<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Repository;

use Maatify\RateLimiter\DTO\AdmissionOutcomeDTO;
use Maatify\RateLimiter\DTO\AdmissionReservationRequestDTO;
use Maatify\RateLimiter\DTO\AdmissionReservationResultDTO;

/**
 * Store capability for the atomic admission reservation (DEC-024).
 *
 * Contract for implementations (`RedisFullCapabilityStore` implements it):
 * - `reserveAdmission` validates every key and field before its first write and then writes all of the
 *   scopes or none; a refusal writes nothing, never prunes and never writes a previous generation.
 * - Availability failures are reported through the typed backend-failure contract. In
 *   `RedisFullCapabilityStore`, malformed stored state is a Lua error reply `malformed admission state: …`,
 *   returned before any write. The reference test executor (`RespRedisCommandExecutor`) raises it as
 *   `RateLimiterException`; a Host executor whose client throws on an error reply (phpredis: `RedisException`)
 *   propagates that exception unchanged, as `RedisCommandExecutorInterface` requires. It is never a
 *   `BackendFailureException` and never a fallback.
 * - `readAdmissionFence` reads the current fence key, else the previous fence key, else 0; `bumpAdmissionFence`
 *   writes the current fence key only and returns the new fence value (DEC-048 D3/D4).
 * - `readBindingEpoch` reads a single durable account binding-epoch key, else 0; `bumpBindingEpoch` atomically
 *   increments that single key and returns the new epoch (no previous-key dependency; DEC-048 D3/D4).
 *
 * Used by the admission runtime the builder constructs for a policy that opts in (DEC-026, DEC-048).
 */
interface AdmissionReservationStoreInterface extends RateLimitStoreInterface
{
    /**
     * Reserve every scope of `$request` as one operation, judged at `$now` (Unix seconds, non-negative).
     *
     * Admitted: every scope is reserved and, when the request carries a permit, its permit record is created.
     * Refused: nothing is written; the result names the first refusing scope and the seconds to wait. A permit id that
     * already exists refuses with the scope name `permit_exists`. A request that carries shared configuration
     * (`AdmissionSharedConfigDTO`) registers it when its group has none and is compared with it otherwise.
     *
     * @throws \Maatify\RateLimiter\Exception\AdmissionConfigurationException when the carried shared configuration
     *         differs from the registered one; nothing is reserved
     * @throws \Maatify\RateLimiter\Exception\RateLimiterException when the request or `$now` is not valid or
     *         representable, stored state is malformed, or a direct Redis ACL preflight refuses a planned write command
     *         (`NOPERM admission reservation: …`); raised before any write
     */
    public function reserveAdmission(AdmissionReservationRequestDTO $request, int $now): AdmissionReservationResultDTO;

    /**
     * Apply the recorded outcome to the scopes its admission touched, judged at `$now` (Unix seconds, non-negative).
     *
     * A failure settles the member of a set-size scope; a success cancels a pending member that is not yet due; a
     * counter scope refunds or releases its member as `AdmissionScopeDTO` describes. An outcome without scopes does
     * nothing. This method does not claim a permit: an outcome bound to a permit goes through
     * `AdmissionPermitStoreInterface::claimAdmissionPermit()` or the atomic claim of
     * `AtomicAdmissionPermitStoreInterface`.
     *
     * @throws \Maatify\RateLimiter\Exception\RateLimiterException when a scope or `$now` is not valid or representable,
     *         or stored state is malformed; raised before any write
     */
    public function applyAdmissionOutcome(AdmissionOutcomeDTO $outcome, int $now): void;

    /**
     * The fleet fence stored for `$key`: the current fence key's value, else the previous fence key's, else 0 (DEC-048 D3).
     *
     * Stored in a physical family completely separate from binding epochs. `$previousKey` is read only and is ignored
     * when it equals `$key`. Nothing is written.
     *
     * @throws \Maatify\RateLimiter\Exception\RateLimiterException when a key is blank, stored state is malformed, or the stored value exceeds the caller's native integer range
     */
    public function readAdmissionFence(string $key, ?string $previousKey): int;

    /**
     * Raise the fleet fence of `$key` by one and return the new value (DEC-048 D3).
     *
     * Only the current fence key is written. The new value is one more than the larger of the current and previous
     * fence values. Overflow of the caller's native integer range or Lua's exact range fails before any write.
     *
     * @throws \Maatify\RateLimiter\Exception\RateLimiterException when a key is blank, stored state is malformed, or bumping would overflow
     */
    public function bumpAdmissionFence(string $key, ?string $previousKey): int;

    /**
     * The durable account binding epoch stored at single stable `$key`, else 0 (DEC-048 D3/D4).
     *
     * Stored in a physical family completely separate from fleet fences, with no previous-key fallback and no TTL.
     * Nothing is written.
     *
     * @throws \Maatify\RateLimiter\Exception\RateLimiterException when `$key` is blank, stored state is malformed, or the stored value exceeds the caller's native integer range
     */
    public function readBindingEpoch(string $key): int;

    /**
     * Atomically raise the durable account binding epoch at `$key` by one and return the new value (DEC-048 D3/D4).
     *
     * Overflow of the caller's native integer range or Lua's exact range fails before any write.
     *
     * @throws \Maatify\RateLimiter\Exception\RateLimiterException when `$key` is blank, stored state is malformed, or bumping would overflow
     */
    public function bumpBindingEpoch(string $key): int;
}
