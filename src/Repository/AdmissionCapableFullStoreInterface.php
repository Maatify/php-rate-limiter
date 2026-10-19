<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Repository;

/**
 * `FullCapabilityStoreInterface` plus the admission reservation capability. `FullCapabilityStoreInterface`
 * itself is not edited, so existing implementers stay valid.
 */
interface AdmissionCapableFullStoreInterface extends FullCapabilityStoreInterface, AdmissionReservationStoreInterface {}
