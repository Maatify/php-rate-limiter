<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\DTO;

use Maatify\RateLimiter\Config\FailureFallbackDimension;

/**
 * The effective bounded backend-failure fallback configuration for a policy.
 *
 * This is the single generic runtime contract shared by package-owned
 * official presets and host-owned direct custom policies alike; the runtime
 * applies exactly the rules it is given without knowing whether they were
 * produced by a preset or a custom policy.
 */
final readonly class FailureFallbackConfigurationDTO implements \JsonSerializable
{
    /** @var list<FailureFallbackRuleDTO> */
    public array $rules;

    /**
     * @param list<FailureFallbackRuleDTO> $rules Bounded rules for this configuration.
     */
    public function __construct(array $rules)
    {
        $this->rules = $rules;
    }

    /**
     * Return the rule declared for the given dimension, or null when absent.
     */
    public function ruleFor(FailureFallbackDimension $dimension): ?FailureFallbackRuleDTO
    {
        foreach ($this->rules as $rule) {
            if ($rule->dimension === $dimension) {
                return $rule;
            }
        }

        return null;
    }

    /**
     * Return whether a rule is declared for the given dimension.
     */
    public function hasDimension(FailureFallbackDimension $dimension): bool
    {
        return $this->ruleFor($dimension) !== null;
    }

    /**
     * Return the configured rules in serialized form.
     */
    public function jsonSerialize(): mixed
    {
        return array_map(
            static fn(FailureFallbackRuleDTO $rule): mixed => $rule->jsonSerialize(),
            $this->rules,
        );
    }
}
