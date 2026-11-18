<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\Support\Ownership;

use Maatify\RateLimiter\Service\OwnershipRegistry;

/** Counts the binding records reachable from the registry slot of a resource, on a registry that chains older ones too (DEC-041, F266-19). */
final class BindingProbe
{
    public static function records(object $resource): int
    {
        $slot = (new \ReflectionMethod(OwnershipRegistry::class, 'slot'))->invoke(null, $resource);
        if (! is_object($slot)) {
            return 0;
        }
        $generation = (new \ReflectionProperty($slot, 'generation'))->getValue($slot);
        $count = 0;
        while (is_object($generation)) {
            $count++;
            $next = null;
            foreach ((new \ReflectionObject($generation))->getProperties() as $property) {
                if ($property->getName() === 'previous') {
                    $next = $property->getValue($generation);
                }
            }
            $generation = $next;
        }

        return $count;
    }
}
