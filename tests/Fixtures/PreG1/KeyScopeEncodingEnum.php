<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\Fixtures\PreG1;

/**
 * The derivation of the account-bearing keys of a runtime (DEC-044, `docs/KEY_SCOPE_ENCODING_E1.md`).
 *
 * `V2` is the default and the shipped derivation: text joined with `:`. `E1` is the opt-in, length-prefixed encoding with its own HMAC key
 * domain and its own physical key prefix. It is chosen once, when a `RateLimiterBuilder` composes a runtime and its reader.
 */
enum KeyScopeEncodingEnum: string
{
    case V2 = 'v2';
    case E1 = 'e1';
}
