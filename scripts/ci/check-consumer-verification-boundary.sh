#!/usr/bin/env bash
set -euo pipefail
root="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/../.." && pwd)"
fixture="$root/consumer-verification"
for forbidden in EvaluationPipeline CircuitBreaker FailureModeResolver BudgetTracker AntiEquilibriumGate DecayCalculator EphemeralBucket RateLimiterEngine; do
    ! rg -n "(?:use .*${forbidden}|new ${forbidden})" "$fixture/verify.php"
done
! find "$fixture/src" -type f \( -name 'InMemory*Store.php' -o -name '*CorrelationStore.php' \) | grep -q .
! rg -n "autoload-dev|tests/Support|require.*src/|Maatify\\\\RateLimiter\\\\Tests" "$fixture"
rg -q 'RateLimiterBuilder::fromFullCapabilityStore' "$fixture/verify.php"
rg -q 'RedisFullCapabilityStore' "$fixture/verify.php"
