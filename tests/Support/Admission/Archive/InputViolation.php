<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\Support\Admission\Archive;

/** A replayed row whose instance id does not reach the context (IN, g4v2.php:20-41: `G4V2InputViolation`, the F131-01 class). */
final class InputViolation extends \RuntimeException {}
