# #227 audit: adjacent int-returning counter paths on a 32-bit PHP

Evidence for the follow-up that the Lead asked to be reported separately; no code outside the score increment is changed by #227.
`audit32.php` seeds each counter at the caller's `PHP_INT_MAX` (2147483647 on 32-bit, 9223372036854775807 on 64-bit), increments it once, and reports the outcome and whether the stored state changed (byte-exact `DUMP` and exact `PEXPIRETIME` of every key). PHP 8.4.27, Redis 7.0.15 isolated, `linux/386` image for the 32-bit run, run on the head of #227.

## PHP_INT_SIZE 4
| Path | Outcome | Stored state changed |
| --- | --- | --- |
| score increment (#227, fixed) | Maatify\RateLimiter\Exception\RateLimiterException: score integer overflow | no |
| budget increment | Maatify\RateLimiter\Exception\RateLimiterException: Malformed budget count. | **yes** |
| budget increment with seed | Maatify\RateLimiter\Exception\RateLimiterException: Malformed budget count. | **yes** |
| watch flag increment | Maatify\RateLimiter\Exception\RateLimiterException: Malformed watch count response. | **yes** |

## PHP_INT_SIZE 8 (reference)

| Path | Outcome | Stored state changed |
| --- | --- | --- |
| score increment (#227, fixed) | Maatify\RateLimiter\Exception\RateLimiterException: score integer overflow | no |
| budget increment | Maatify\RateLimiter\Exception\RateLimiterException: budget count overflow | no |
| budget increment with seed | Maatify\RateLimiter\Exception\RateLimiterException: budget count overflow | no |
| watch flag increment | Maatify\RateLimiter\Exception\RateLimiterException: watch count overflow | no |

## Reading

On a 64-bit PHP the budget and watch scripts reject the overflow before writing (`budget count overflow`, `watch count overflow`, F-185-25). On a 32-bit PHP the same three paths write the value (2147483648) and PHP rejects the reply afterwards with a different message (`Malformed budget count.`, `Malformed watch count response.`): the same defect as #227 for the score, in `incrementBudget` / `incrementBudgetAt`, `incrementBudgetWithSeed(At)` and `incrementWatchFlag(At)`; the rotated watch (`incrementWatchFlagAcrossRotationAt`) and the fixed-window simple throttle (which consumes a budget) were not run separately. They are not fixed here.
