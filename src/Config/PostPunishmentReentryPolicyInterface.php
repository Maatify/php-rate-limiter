<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Config;

/** Opts a policy into generation-bound authentication K4 re-entry. */
interface PostPunishmentReentryPolicyInterface extends BlockPolicyInterface {}
