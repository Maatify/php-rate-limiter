<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Exception;

/**
 * The effective shared admission configuration of this worker differs from the one registered on the shared store for the
 * budget group, or its consistency cannot be proven (DEC-038). Raised before the reservation is accepted; nothing is reserved
 * and the worker never changes the shared value itself.
 */
final class AdmissionConfigurationException extends RateLimiterException {}
