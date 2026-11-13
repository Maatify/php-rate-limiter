<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Service;

/**
 * Operator control of the shared admission configuration of a budget group (DEC-038). Every worker on one Redis must hold the
 * same effective configuration, including "no pending-permit limit"; a worker whose value differs raises an
 * `AdmissionConfigurationException` before it reserves, and never changes the shared value itself. This call is the explicit way
 * to change it, after the fleet has been drained or replaced as `docs/guides/USAGE_GUIDE.md` describes.
 * A separate interface so that existing implementers of the runtime interfaces stay valid.
 */
interface AdmissionSharedConfigControlInterface
{
    /**
     * Register this worker's effective configuration for `$budgetGroup` as the shared one, replacing the registered value.
     *
     * @throws \Maatify\RateLimiter\Exception\RateLimiterException when no admission runtime is configured, or the store cannot hold it
     */
    public function rebindAdmissionSharedConfig(string $budgetGroup): void;
}
