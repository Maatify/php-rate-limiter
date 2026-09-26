<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Config;

/**
 * Opts an authentication policy into generation-bound K4 re-entry.
 *
 * Implementers accept the package lifecycle contract: real K4 mutations are
 * generation-fenced, punishment evidence is published atomically with the
 * qualifying L2+ hard block, and the public claim is a bounded one-shot
 * application handoff. This capability is intentionally not enabled for
 * non-authentication policy families. Implementers must expose positive,
 * monotonic K4 thresholds and fail-closed failure mode; the runtime rejects
 * opt-in policies that violate those constraints, and the Builder requires
 * PunishmentLifecycleStoreInterface storage.
 */
interface PostPunishmentReentryPolicyInterface extends BlockPolicyInterface {}
