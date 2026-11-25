<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\Support\Redis;

use Maatify\RateLimiter\Config\KnownDeviceAccountAllowancePolicyInterface;
use Maatify\RateLimiter\DTO\BudgetConfigDTO;
use Maatify\RateLimiter\DTO\PolicyThresholdsDTO;
use Maatify\RateLimiter\DTO\ScoreDeltasDTO;
use Maatify\RateLimiter\DTO\ScoreThresholdsDTO;

/** Advanced-Pipeline allowance without a budget; Builder rejects it under authentication validation. */
class AllowanceOnlyPolicy implements KnownDeviceAccountAllowancePolicyInterface
{
    public int $allowanceReads = 0;
    public ?\Closure $onAllowance = null;
    public ?\Closure $onThresholds = null;

    public function __construct(private readonly string $name = 'allowance', private readonly ?int $limit = 8, private readonly int $k5 = 2) {}

    public function getName(): string
    {
        return $this->name;
    }
    public function getBudgetConfig(): ?BudgetConfigDTO
    {
        return null;
    }
    public function getFailureMode(): string
    {
        return 'FAIL_CLOSED';
    }
    public function getScoreDeltas(): ScoreDeltasDTO
    {
        return new ScoreDeltasDTO(k1_spray: 1, k4_failure: 3, k5_failure: $this->k5);
    }
    public function getScoreThresholds(): PolicyThresholdsDTO
    {
        ($this->onThresholds)?->__invoke();
        return new PolicyThresholdsDTO(k1: new ScoreThresholdsDTO(1, 100, 200), k4: new ScoreThresholdsDTO(1, 100, 200), k5: new ScoreThresholdsDTO(100, 200, 300));
    }
    public function getKnownDeviceAccountAllowance(): ?int
    {
        $this->allowanceReads++;
        ($this->onAllowance)?->__invoke();
        return $this->limit;
    }
}
