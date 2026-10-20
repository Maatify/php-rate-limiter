<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\Support\Admission;

use Maatify\RateLimiter\Command\RateLimitCommand;
use Maatify\RateLimiter\Config\BlockPolicyInterface;
use Maatify\RateLimiter\DTO\DeviceIdentityDTO;
use Maatify\RateLimiter\DTO\RateLimitContextDTO;
use Maatify\RateLimiter\DTO\RateLimitResultDTO;
use Maatify\RateLimiter\Enum\AdmissionLaneEnum;
use Maatify\RateLimiter\Service\EvaluationPipeline;

/** The real pipeline, counting the outcome commands it scores (NM-10 (b): "scored once per permit"). It changes no behaviour. */
final class CountingPipeline extends EvaluationPipeline
{
    public int $scoredOutcomes = 0;

    public function process(
        BlockPolicyInterface $policy,
        RateLimitContextDTO $context,
        RateLimitCommand $request,
        DeviceIdentityDTO $device,
        ?AdmissionLaneEnum $lane = null,
    ): RateLimitResultDTO {
        if ($request->isFailure || $request->isSuccess) {
            $this->scoredOutcomes++;
        }

        return parent::process($policy, $context, $request, $device, $lane);
    }
}
