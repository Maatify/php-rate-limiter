<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Service;

use Maatify\RateLimiter\Config\BlockPolicyInterface;
use Maatify\RateLimiter\DTO\RateLimitContextDTO;
use Maatify\RateLimiter\DTO\RateLimitOperationalSnapshotDTO;
use Maatify\RateLimiter\DTO\SimpleRateLimitOperationalSnapshotDTO;
use Maatify\RateLimiter\Exception\RateLimiterException;

/**
 * Thin delegating/composition owner for the Production Default Read Path
 * (DEC-012), analogous in purpose to CompositeRateLimiterRuntime.
 *
 * It resolves score policies by name against the Builder's registered
 * score-policy graph and delegates the actual read to the existing Advanced
 * Path RateLimitOperationalReaderInterface; simple-throttle reads are
 * delegated to SimpleRateLimitOperationalReaderInterface, which already
 * resolves its own registered policies by name. This object does not
 * re-implement either collaborator's behavior.
 */
final class CompositeRateLimitOperationalReader implements CompositeRateLimitOperationalReaderInterface
{
    /** @var array<string, BlockPolicyInterface> */
    private readonly array $scorePolicies;

    /**
     * @param BlockPolicyInterface[] $scorePolicies The Builder's currently
     *     registered score-policy graph.
     */
    public function __construct(
        private readonly RateLimitOperationalReaderInterface $scoreReader,
        array $scorePolicies,
        private readonly SimpleRateLimitOperationalReaderInterface $simpleReader,
    ) {
        $indexed = [];
        foreach ($scorePolicies as $policy) {
            $indexed[$policy->getName()] = $policy;
        }
        $this->scorePolicies = $indexed;
    }

    public function readScorePolicy(
        RateLimitContextDTO $context,
        string $policyName,
    ): RateLimitOperationalSnapshotDTO {
        $policy = $this->scorePolicies[$policyName] ?? null;
        if ($policy === null) {
            throw new RateLimiterException(sprintf('Unknown score policy "%s".', $policyName));
        }

        return $this->scoreReader->read($context, $policy);
    }

    public function readSimpleThrottle(
        string $policyName,
        string $subject,
    ): SimpleRateLimitOperationalSnapshotDTO {
        return $this->simpleReader->read($policyName, $subject);
    }
}
