# Fix coverage: the 38 call sites of the shared expiry helper (DEC-043)

Generated from an instrumented, throw-away copy of the source in which each site tagged its refusal with a site id; the verification matrix of `ExpiryProvenanceDirectStudy270Test` was run on PHP64 (RESP and phpredis, wide account). A site is *reached* when at least one matrix row's denied call answered `NOPERM` from it. The instrumented copy is not shipped; the shipped source has no tags.

Reached: 31 of 38. Not reached: S21, S23, S25, S27, S29, S31, S38 (all are the *second* expiry of a data-key / metadata-key pair, applied only after the first one succeeded; denying the command stops at the first).

| Site | Script | Command | Key | Created keys deleted on refusal | Branch | First matrix label that reaches it |
| --- | --- | --- | --- | --- | --- | --- |
| S01 | `SCORE_INCREMENT` | `EXPIRE` | `KEYS[1]` | `{KEYS[1]}` | `if not ttlBackendRepresentable(ARGV[1]) then return redis.error_reply(` | `incrementAt` |
| S02 | `SCORE_SET_IF_SNAPSHOT` | `EXPIRE` | `KEYS[1]` | `{KEYS[1]}` | `if not ttlBackendRepresentable(ARGV[1]) then return redis.error_reply(` | `incrementIfSnapshotAt` |
| S03 | `SCORE_REPLACE` | `EXPIRE` | `KEYS[1]` | `{}` | `if not value or not updated or not validPhpInteger(value) or not valid` | `replaceScoreAt` |
| S04 | `SCORE_REPLACE` | `EXPIRE` | `KEYS[1]` | `{KEYS[1]}` | `else` | `replaceScoreAt` |
| S05 | `SCORE_SET` | `EXPIRE` | `KEYS[1]` | `{}` | `if redis.call('EXISTS', KEYS[1]) == 1 then` | `setAt` |
| S06 | `SCORE_SET` | `EXPIRE` | `KEYS[1]` | `{KEYS[1]}` | `else` | `setAt` |
| S07 | `LIFECYCLE_MUTATE` | `EXPIRE` | `current` | `{}` | `if overwrite then` | `mutateGenerationBoundScoreAtMilliseconds (logically expired Current)` |
| S08 | `LIFECYCLE_MUTATE` | `EXPIRE` | `current` | `{current}` | `else` | `mutateGenerationBoundScoreAtMilliseconds` |
| S09 | `LIFECYCLE_MUTATE` | `PEXPIRE` | `current` | `{}` | `if redis.call('EXISTS', current) == 1 then` | `mutateGenerationBoundScoreAtMilliseconds (expected snapshot, live Current)` |
| S10 | `LIFECYCLE_MUTATE` | `PEXPIRE` | `current` | `{current}` | `else` | `mutateGenerationBoundScoreAtMilliseconds (expected snapshot, state held in Previous)` |
| S11 | `LIFECYCLE_CLAIM` | `PEXPIRE` | `KEYS[5]` | `{KEYS[5]}` | `if existing == id then return 0 end` | `claimPostPunishmentReentryAtMilliseconds (block ended, score still alive)` |
| S12 | `BLOCK_SET` | `EXPIRE` | `KEYS[1]` | `{}` | `if stored > requested or (stored == requested and compareDecimalString` | `blockAt (stronger than the stored block)` |
| S13 | `BLOCK_SET` | `EXPIRE` | `KEYS[1]` | `{KEYS[1]}` | `else` | `blockAt` |
| S14 | `BUDGET_INCREMENT` | `EXPIRE` | `KEYS[1]` | `{}` | `if exists == 1 then` | `incrementBudgetAt (epoch ended, key still present)` |
| S15 | `BUDGET_INCREMENT` | `EXPIRE` | `KEYS[1]` | `{KEYS[1]}` | `if exists == 1 then` | `incrementBudgetAt` |
| S16 | `BUDGET_SEED` | `EXPIRE` | `KEYS[1]` | `{}` | `if exists == 1 then` | `incrementBudgetWithSeedAt (ended Current, active seed)` |
| S17 | `BUDGET_SEED` | `EXPIRE` | `KEYS[1]` | `{KEYS[1]}` | `if exists == 1 then` | `incrementBudgetWithSeedAt (no Current, active seed)` |
| S18 | `BUDGET_SEED` | `EXPIRE` | `KEYS[1]` | `{}` | `if exists == 1 then` | `incrementBudgetWithSeedAt (ended Current, ended seed)` |
| S19 | `BUDGET_SEED` | `EXPIRE` | `KEYS[1]` | `{KEYS[1]}` | `if exists == 1 then` | `incrementBudgetWithSeedAt (no Current, ended seed)` |
| S20 | `DISTINCT_ADD` | `EXPIRE` | `KEYS[1]` | `{KEYS[1]}` | `if not ttlBackendRepresentable(ARGV[1]) then return redis.error_reply(` | `addDistinctAt` |
| S21 | `DISTINCT_ADD` | `EXPIRE` | `KEYS[2]` | `{KEYS[1], KEYS[2]}` | `if not ttlBackendRepresentable(ARGV[1]) then return redis.error_reply(` | **not reached** (second of a key pair) |
| S22 | `WATCH_INCREMENT` | `EXPIRE` | `KEYS[1]` | `{KEYS[1]}` | `if not ttlBackendRepresentable(ARGV[1]) then return redis.error_reply(` | `incrementWatchFlagAt` |
| S23 | `WATCH_INCREMENT` | `EXPIRE` | `KEYS[2]` | `{KEYS[1], KEYS[2]}` | `if not ttlBackendRepresentable(ARGV[1]) then return redis.error_reply(` | **not reached** (second of a key pair) |
| S24 | `BOUNDED_SNAPSHOT_PLAN` | `EXPIRE` | `setKey` | `{setKey}` | `if plan.initialize then` | `addDeviceCapPairAt` |
| S25 | `BOUNDED_SNAPSHOT_PLAN` | `EXPIRE` | `metaKey` | `{setKey, metaKey}` | `if plan.initialize then` | **not reached** (second of a key pair) |
| S26 | `ROTATED_SNAPSHOT` | `EXPIRE` | `KEYS[1]` | `{KEYS[1]}` | `if staleCurrent then redis.call('DEL', KEYS[1], KEYS[2]) end` | `addDistinctAcrossRotationAt` |
| S27 | `ROTATED_SNAPSHOT` | `EXPIRE` | `KEYS[2]` | `{KEYS[1], KEYS[2]}` | `if staleCurrent then redis.call('DEL', KEYS[1], KEYS[2]) end` | **not reached** (second of a key pair) |
| S28 | `ROTATED_SNAPSHOT` | `EXPIRE` | `KEYS[1]` | `created` | `if staleCurrent then redis.call('DEL', KEYS[1], KEYS[2]); staleCurrent` | `addDistinctBoundedAcrossRotationAtMilliseconds (previous generation present)` |
| S29 | `ROTATED_SNAPSHOT` | `EXPIRE` | `KEYS[2]` | `created` | `if staleCurrent then redis.call('DEL', KEYS[1], KEYS[2]); staleCurrent` | **not reached** (second of a key pair) |
| S30 | `ROTATED_SNAPSHOT` | `PEXPIREAT` | `KEYS[3]` | `created` | `if staleBridge then redis.call('DEL', KEYS[3], KEYS[4]); staleBridge =` | `addDistinctBoundedAcrossRotationAtMilliseconds (previous generation present)` |
| S31 | `ROTATED_SNAPSHOT` | `PEXPIREAT` | `KEYS[4]` | `created` | `if staleBridge then redis.call('DEL', KEYS[3], KEYS[4]); staleBridge =` | **not reached** (second of a key pair) |
| S32 | `LEASE` | `EXPIRE` | `KEYS[1]` | `{}` | `if current then` | `acquireProbeLease (expired lease present)` |
| S33 | `LEASE` | `EXPIRE` | `KEYS[1]` | `{KEYS[1]}` | `else` | `acquireProbeLease` |
| S34 | `HARD_BLOCK` | `EXPIRE` | `key` | `{}` | `if ttl < needed then` | `blockWithCycleTracking` |
| S35 | `HARD_BLOCK` | `EXPIRE` | `KEYS[3]` | `{}` | `elseif redis.call('EXISTS', KEYS[3]) == 1 then` | `blockWithPunishmentLifecycleTrackingAt (longer than the stored block)` |
| S36 | `HARD_BLOCK` | `EXPIRE` | `KEYS[3]` | `{KEYS[3]}` | `else` | `blockWithCycleTracking` |
| S37 | `PAUSE_READ` | `EXPIRE` | `KEYS[1]` | `{KEYS[1]}` | `if staleCurrent then redis.call('DEL', KEYS[1], KEYS[4]) end` | `incrementWatchFlagAcrossRotationAt` |
| S38 | `PAUSE_READ` | `EXPIRE` | `KEYS[4]` | `{KEYS[1], KEYS[4]}` | `if staleCurrent then redis.call('DEL', KEYS[1], KEYS[4]) end` | **not reached** (second of a key pair) |
