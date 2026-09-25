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

    public function testUpdatedAtMustNotBeNegative(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new GenerationBoundScoreStateDTO(GenerationBoundScoreStateDTO::SOURCE_CURRENT, 1, -1, 20, null);
    }

    public function testExpiresAtMustBePositive(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new GenerationBoundScoreStateDTO(GenerationBoundScoreStateDTO::SOURCE_CURRENT, 1, 10, 0, null);
    }

    public function testExpiresAtMustNotPrecedeUpdatedAt(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new GenerationBoundScoreStateDTO(GenerationBoundScoreStateDTO::SOURCE_CURRENT, 1, 20, 10, null);
    }

    public function testEvidenceValidUntilMustEqualExpiresAt(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new GenerationBoundScoreStateDTO(
            GenerationBoundScoreStateDTO::SOURCE_CURRENT,
            1,
            10,
            20,
            1,
            new PostPunishmentReentryStateDTO(str_repeat('a', 32), 21),
        );
    }

    public function testValidLegacyAndGeneratedStatesAreAccepted(): void
    {
        $legacy = new GenerationBoundScoreStateDTO(GenerationBoundScoreStateDTO::SOURCE_CURRENT, 1, 10, 20, null);
        self::assertNull($legacy->generation);

        $generated = new GenerationBoundScoreStateDTO(
            GenerationBoundScoreStateDTO::SOURCE_CURRENT,
            1,
            10,
            20,
            1,
            new PostPunishmentReentryStateDTO(str_repeat('a', 32), 20),
        );
        self::assertSame(1, $generated->generation);
        self::assertNotNull($generated->postPunishmentReentry);
    }
}
