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
 * - `readBindingEpoch` reads the current key, else the previous key, else 0; `bumpBindingEpoch` writes the
 *   current key only and returns the new epoch.
 *
 * Used by the admission runtime the builder constructs for a policy that opts in (DEC-026).
 */
interface AdmissionReservationStoreInterface extends RateLimitStoreInterface
{
    public function reserveAdmission(AdmissionReservationRequestDTO $request, int $now): AdmissionReservationResultDTO;

    public function applyAdmissionOutcome(AdmissionOutcomeDTO $outcome, int $now): void;

    public function readBindingEpoch(string $key, ?string $previousKey): int;

    public function bumpBindingEpoch(string $key, ?string $previousKey): int;
}
