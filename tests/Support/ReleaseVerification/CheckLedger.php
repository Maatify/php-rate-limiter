<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\Support\ReleaseVerification;

/**
 * Ordered record of named verification checks and the failures they imply.
 */
final class CheckLedger
{
    /** @var array<string, array{status: 'PASS'|'FAIL', message: string, details?: mixed}> */
    private array $checks = [];

    /**
     * @param array{status: 'PASS'|'FAIL', message: string, details?: mixed} $check
     */
    public function record(string $name, array $check): void
    {
        $this->checks[$name] = $check;
    }

    /**
     * @return array<string, array{status: 'PASS'|'FAIL', message: string, details?: mixed}>
     */
    public function checks(): array
    {
        return $this->checks;
    }

    /**
     * @return list<string>
     */
    public function failures(): array
    {
        $failures = [];
        foreach ($this->checks as $check) {
            if ($check['status'] === 'FAIL') {
                $failures[] = $check['message'];
            }
        }

        return $failures;
    }
}
