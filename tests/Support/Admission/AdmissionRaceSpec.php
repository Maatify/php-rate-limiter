<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\Support\Admission;

/**
 * One race scenario handed to `race-worker.php`. Scope keys and members may carry the `{i}` placeholder, replaced by the
 * worker's index so that every racer reserves a distinct account or device.
 *
 * @phpstan-type ScopeSpec array{name: string, kind: string, key: string, capacity: int, ttl: int, previousKey?: string|null, member?: string|null, previousMember?: string|null}
 */
final readonly class AdmissionRaceSpec
{
    /** @param list<ScopeSpec> $scopes */
    public function __construct(
        public string $namespace,
        public string $op,
        public string $mode,
        public int $now,
        public array $scopes = [],
        public ?string $permit = null,
        public ?string $issuePermit = null,
        public ?string $outcome = null,
    ) {}

    public function toJson(): string
    {
        return json_encode([
            'namespace' => $this->namespace,
            'op' => $this->op,
            'mode' => $this->mode,
            'now' => $this->now,
            'scopes' => $this->scopes,
            'permit' => $this->permit,
            'issuePermit' => $this->issuePermit,
            'outcome' => $this->outcome,
        ], JSON_THROW_ON_ERROR);
    }

    public static function fromJson(string $json): self
    {
        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($data)) {
            throw new \UnexpectedValueException('Race spec is not an object.');
        }
        $text = static function (string $field) use ($data): string {
            $value = $data[$field] ?? null;
            if (! is_string($value)) {
                throw new \UnexpectedValueException("Race spec field $field is not text.");
            }

            return $value;
        };
        $optional = static function (string $field) use ($data): ?string {
            $value = $data[$field] ?? null;
            if ($value !== null && ! is_string($value)) {
                throw new \UnexpectedValueException("Race spec field $field is not text.");
            }

            return $value;
        };
        $now = $data['now'] ?? null;
        if (! is_int($now)) {
            throw new \UnexpectedValueException('Race spec field now is not an integer.');
        }
        $scopes = [];
        foreach (is_array($data['scopes'] ?? null) ? $data['scopes'] : [] as $scope) {
            if (! is_array($scope) || ! is_string($scope['name'] ?? null) || ! is_string($scope['kind'] ?? null) || ! is_string($scope['key'] ?? null)
                || ! is_int($scope['capacity'] ?? null) || ! is_int($scope['ttl'] ?? null)) {
                throw new \UnexpectedValueException('Race spec scope is malformed.');
            }
            $scopes[] = [
                'name' => $scope['name'],
                'kind' => $scope['kind'],
                'key' => $scope['key'],
                'capacity' => $scope['capacity'],
                'ttl' => $scope['ttl'],
                'previousKey' => is_string($scope['previousKey'] ?? null) ? $scope['previousKey'] : null,
                'member' => is_string($scope['member'] ?? null) ? $scope['member'] : null,
                'previousMember' => is_string($scope['previousMember'] ?? null) ? $scope['previousMember'] : null,
            ];
        }

        return new self($text('namespace'), $text('op'), $text('mode'), $now, $scopes, $optional('permit'), $optional('issuePermit'), $optional('outcome'));
    }
}
