<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\DTO;

use Maatify\RateLimiter\Exception\RateLimiterException;

/** The permit record an admission creates (tier T1). The deadline and the grace are caller-supplied. */
final readonly class AdmissionPermitDTO
{
    public function __construct(
        public string $id,
        public string $lane,
        public string $subject,
        public string $operation,
        public int $deadlineAt,
        public int $graceSeconds,
    ) {
        if (trim($id) === '' || trim($lane) === '' || trim($subject) === '' || trim($operation) === '') {
            throw new RateLimiterException('Admission permit id, lane, subject and operation must not be empty.');
        }
        if ($deadlineAt < 0 || $graceSeconds < 0) {
            throw new RateLimiterException('Admission permit deadline and grace must not be negative.');
        }
    }
}
