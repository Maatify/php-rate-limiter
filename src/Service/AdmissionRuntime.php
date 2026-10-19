<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Service;

/**
 * The admission runtime that a policy declaring `PolicyCapabilityEnum::ADMISSION_RESERVATION` needs.
 *
 * @internal Not part of the supported composition. It is an empty shell in this change: the builder never
 *           constructs it, so no policy can opt in.
 */
final class AdmissionRuntime {}
