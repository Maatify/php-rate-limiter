# Admission reservation and recorded outcomes (#185)

Failure-set counts describe recorded outcomes. The cap applies to subsequent reservations. It does not describe how many outstanding permits exist.

## Lane U

The public Redis regression `AdmissionPublicRuntimeRedisTest::testUnknownLaneSourceCapacityUnderParallelPublicChecks` builds the runtime through `RateLimiterBuilder`. It checks 24 admitted requests and 36 refusals with cause `source_capacity` in a batch of 60, with one source counter holding 24 entries. This is evidence for the tested batch and configuration. The failure-set cap of 4 is a separate check on recorded failures under DEC-033.

## Lane P

The older observations are recorded in the [Lead review of the previous PR-Q head](https://github.com/Maatify/php-rate-limiter/pull/202#pullrequestreview-5855399154). That historical run admitted all 60 distinct-account, distinct-proof reservations in its largest batch before outcomes. This does not establish an unlimited capacity, a bound on the current head, or acceptance of the cost by the Owner.

The [Owner's scope decision](https://github.com/Maatify/php-rate-limiter/issues/185#issuecomment-6805835020) leaves the implementation and public Redis evidence for the pending-permit limit to [#206](https://github.com/Maatify/php-rate-limiter/issues/206). The old measurement harness was retired in favor of the public-runtime tests and the separate #206 acceptance criteria.
