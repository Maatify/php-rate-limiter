<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\Unit\DTO;

use Maatify\RateLimiter\DTO\GenerationBoundScoreStateDTO;
use Maatify\RateLimiter\DTO\PostPunishmentReentryStateDTO;
use PHPUnit\Framework\TestCase;

final class GenerationBoundScoreStateDTOContractTest extends TestCase
{
    public function testGeneratedGenerationMustBePositive(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new GenerationBoundScoreStateDTO(GenerationBoundScoreStateDTO::SOURCE_CURRENT, 1, 10, 20, 0);
    }

    public function testLegacyStateCannotCarryLifecycleEvidence(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new GenerationBoundScoreStateDTO(
            GenerationBoundScoreStateDTO::SOURCE_CURRENT,
            1,
            10,
            20,
            null,
            new PostPunishmentReentryStateDTO(str_repeat('a', 32), 20),
        );
    }
}
