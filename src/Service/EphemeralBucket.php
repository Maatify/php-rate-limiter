<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Service;

use Maatify\RateLimiter\DTO\BoundedCorrelationObservationDTO;
use Maatify\RateLimiter\DTO\BoundedDistinctResultDTO;
use Maatify\RateLimiter\DTO\EphemeralStateDTO;
use Maatify\RateLimiter\Exception\RateLimiterException;
use Maatify\RateLimiter\Repository\BoundedCorrelationRotationStoreInterface;
use Maatify\RateLimiter\Repository\BoundedCorrelationStoreInterface;
use Maatify\RateLimiter\Repository\CorrelationStoreInterface;

/**
 * Classifies new device-cap overflows without owning raw identity material.
 */
class EphemeralBucket
{
    private const MAX_DEVICES_PER_ACCOUNT = 10;
    private const MAX_DEVICES_PER_IP = 50;
    private const CAP_WINDOW = 900;

    /**
     * @param CorrelationStoreInterface $store Distinct-device correlation store.
     */
    public function __construct(
        private readonly CorrelationStoreInterface $store,
    ) {}

    /**
     * Observe both device-cap scopes exactly once.
     */
    public function check(
        BoundedCorrelationObservationDTO $ipObservation,
        ?BoundedCorrelationObservationDTO $accountObservation = null,
    ): EphemeralStateDTO {
        $this->assertCapabilities($ipObservation, $accountObservation);

        $accountResult = $accountObservation === null
            ? null
            : $this->addBounded($accountObservation, self::MAX_DEVICES_PER_ACCOUNT);
        $ipResult = $this->addBounded($ipObservation, self::MAX_DEVICES_PER_IP);

        return new EphemeralStateDTO(
            ($accountResult !== null && ! $accountResult->accepted) || ! $ipResult->accepted,
            $accountResult === null ? 0 : $accountResult->count,
            $ipResult->count,
        );
    }

    private function assertCapabilities(
        BoundedCorrelationObservationDTO $ipObservation,
        ?BoundedCorrelationObservationDTO $accountObservation,
    ): void {
        $observations = array_filter(
            [$ipObservation, $accountObservation],
            static fn(?BoundedCorrelationObservationDTO $observation): bool => $observation !== null,
        );

        $requiresRotation = false;
        foreach ($observations as $observation) {
            if ($observation->previousKey !== null) {
                $requiresRotation = true;
                break;
            }
        }

        if ($requiresRotation && ! $this->store instanceof BoundedCorrelationRotationStoreInterface) {
            throw new RateLimiterException(
                'Bounded correlation rotation requires the BoundedCorrelationRotationStoreInterface capability.',
            );
        }

        if (! $requiresRotation && ! $this->store instanceof BoundedCorrelationStoreInterface) {
            throw new RateLimiterException(
                'Bounded correlation requires the BoundedCorrelationStoreInterface capability.',
            );
        }
    }

    private function addBounded(
        BoundedCorrelationObservationDTO $observation,
        int $maxDistinct,
    ): BoundedDistinctResultDTO {
        if ($observation->previousKey === null) {
            if (! $this->store instanceof BoundedCorrelationStoreInterface) {
                throw new RateLimiterException(
                    'Bounded correlation requires the BoundedCorrelationStoreInterface capability.',
                );
            }

            $result = $this->store->addDistinctBounded(
                $observation->currentKey,
                $observation->currentMember,
                self::CAP_WINDOW,
                $maxDistinct,
            );

            BoundedCorrelationResultValidator::count($result, $maxDistinct);

            return $result;
        }

        if (! $this->store instanceof BoundedCorrelationRotationStoreInterface) {
            throw new RateLimiterException(
                'Bounded correlation rotation requires the BoundedCorrelationRotationStoreInterface capability.',
            );
        }

        $bridgeKey = $observation->bridgeKey;
        $previousMember = $observation->previousMember;
        assert($bridgeKey !== null && $previousMember !== null);

        $result = $this->store->addDistinctBoundedAcrossRotation(
            $observation->currentKey,
            $bridgeKey,
            $observation->previousKey,
            $observation->currentMember,
            $previousMember,
            self::CAP_WINDOW,
            $maxDistinct,
        );

        BoundedCorrelationResultValidator::count($result, $maxDistinct);

        return $result;
    }
}
