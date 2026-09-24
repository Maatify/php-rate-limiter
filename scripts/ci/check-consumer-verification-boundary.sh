#!/usr/bin/env bash
set -euo pipefail
root="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/../.." && pwd)"
fixture="$root/consumer-verification"
for forbidden in EvaluationPipeline CircuitBreaker FailureModeResolver BudgetTracker AntiEquilibriumGate DecayCalculator EphemeralBucket RateLimiterEngine; do
    ! grep -REn "(use .*${forbidden}|new ${forbidden})" "$fixture"
done
! find "$fixture/src" -type f \( -name 'InMemory*Store.php' -o -name '*CorrelationStore.php' \) | grep -q .
! grep -REn "autoload-dev|tests/Support|Maatify\\\\RateLimiter\\\\Tests|(^|[[:space:];])(require|include)(_once)?[[:space:]].*src/" "$fixture"
grep -Eq 'RateLimiterBuilder::fromFullCapabilityStore' "$fixture/verify.php"
grep -Eq 'RedisFullCapabilityStore' "$fixture/verify.php"
