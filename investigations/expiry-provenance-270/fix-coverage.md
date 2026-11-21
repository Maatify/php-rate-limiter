# Fix coverage: the 38 call sites of the shared expiry helper (DEC-043)

Generated from an instrumented, throw-away copy of the source in which each site tagged its refusal with a site id (the copy inlines the two bridge sites that the shipped source routes through `expireBridgeKey`; the helper is the same). The verification matrix of `ExpiryProvenanceDirectStudy270Test` was run on PHP64 (RESP and phpredis). A site is *reached* when a matrix row's denied call answered `NOPERM` from it.

Reached: 38 of 38.
- 31 sites by denying the command on every key (`kind` `wide` / `limited`).
- 7 sites (S21, S23, S25, S27, S29, S31, S38) are the **second** expiry of a data-key / metadata-key pair: a command-level denial stops at the first, so they are reached with Redis ACL selectors that let the command run on the first key and refuse it on the second (`kind` `second`; Redis logs a *key* refusal inside the script).

| Site | Script | Command | Key | Created keys deleted on refusal | Branch | Reached by (kind / first label) |
| --- | --- | --- | --- | --- | --- | --- |
| S01 | `SCORE_INCREMENT` | `EXPIRE` | `KEYS[1]` | `{KEYS[1]}` | `if not ttlBackendRepresentable(ARGV[1]) then return redis.error_reply(` | `limited` / `incrementAt` |
| S02 | `SCORE_SET_IF_SNAPSHOT` | `EXPIRE` | `KEYS[1]` | `{KEYS[1]}` | `if not ttlBackendRepresentable(ARGV[1]) then return redis.error_reply(` | `wide` / `incrementIfSnapshotAt` |
| S03 | `SCORE_REPLACE` | `EXPIRE` | `KEYS[1]` | `{}` | `if not value or not updated or not validPhpInteger(value) or not valid` | `wide` / `replaceScoreAt` |
| S04 | `SCORE_REPLACE` | `EXPIRE` | `KEYS[1]` | `{KEYS[1]}` | `else` | `wide` / `replaceScoreAt` |
| S05 | `SCORE_SET` | `EXPIRE` | `KEYS[1]` | `{}` | `if redis.call('EXISTS', KEYS[1]) == 1 then` | `wide` / `setAt` |
| S06 | `SCORE_SET` | `EXPIRE` | `KEYS[1]` | `{KEYS[1]}` | `else` | `wide` / `setAt` |
| S07 | `LIFECYCLE_MUTATE` | `EXPIRE` | `current` | `{}` | `if overwrite then` | `wide` / `mutateGenerationBoundScoreAtMilliseconds (logically expired Current)` |
| S08 | `LIFECYCLE_MUTATE` | `EXPIRE` | `current` | `{current}` | `else` | `wide` / `mutateGenerationBoundScoreAtMilliseconds` |
| S09 | `LIFECYCLE_MUTATE` | `PEXPIRE` | `current` | `{}` | `if redis.call('EXISTS', current) == 1 then` | `wide` / `mutateGenerationBoundScoreAtMilliseconds (expected snapshot, live Current)` |
| S10 | `LIFECYCLE_MUTATE` | `PEXPIRE` | `current` | `{current}` | `else` | `wide` / `mutateGenerationBoundScoreAtMilliseconds (expected snapshot, state held in Previous)` |
| S11 | `LIFECYCLE_CLAIM` | `PEXPIRE` | `KEYS[5]` | `{KEYS[5]}` | `if existing == id then return 0 end` | `wide` / `claimPostPunishmentReentryAtMilliseconds (block ended, score still alive)` |
| S12 | `BLOCK_SET` | `EXPIRE` | `KEYS[1]` | `{}` | `if stored > requested or (stored == requested and compareDecimalString` | `wide` / `blockAt (stronger than the stored block)` |
| S13 | `BLOCK_SET` | `EXPIRE` | `KEYS[1]` | `{KEYS[1]}` | `else` | `limited` / `blockAt` |
| S14 | `BUDGET_INCREMENT` | `EXPIRE` | `KEYS[1]` | `{}` | `if exists == 1 then` | `wide` / `incrementBudgetAt (epoch ended, key still present)` |
| S15 | `BUDGET_INCREMENT` | `EXPIRE` | `KEYS[1]` | `{KEYS[1]}` | `if exists == 1 then` | `wide` / `incrementBudgetAt` |
| S16 | `BUDGET_SEED` | `EXPIRE` | `KEYS[1]` | `{}` | `if exists == 1 then` | `wide` / `incrementBudgetWithSeedAt (ended Current, active seed)` |
| S17 | `BUDGET_SEED` | `EXPIRE` | `KEYS[1]` | `{KEYS[1]}` | `if exists == 1 then` | `wide` / `incrementBudgetWithSeedAt (no Current, active seed)` |
| S18 | `BUDGET_SEED` | `EXPIRE` | `KEYS[1]` | `{}` | `if exists == 1 then` | `wide` / `incrementBudgetWithSeedAt (ended Current, ended seed)` |
| S19 | `BUDGET_SEED` | `EXPIRE` | `KEYS[1]` | `{KEYS[1]}` | `if exists == 1 then` | `wide` / `incrementBudgetWithSeedAt (no Current, ended seed)` |
| S20 | `DISTINCT_ADD` | `EXPIRE` | `KEYS[1]` | `{KEYS[1]}` | `if not ttlBackendRepresentable(ARGV[1]) then return redis.error_reply(` | `limited` / `addDistinctAt` |
| S21 | `DISTINCT_ADD` | `EXPIRE` | `KEYS[2]` | `{KEYS[1], KEYS[2]}` | `if not ttlBackendRepresentable(ARGV[1]) then return redis.error_reply(` | `second` / `addDistinctAt` |
| S22 | `WATCH_INCREMENT` | `EXPIRE` | `KEYS[1]` | `{KEYS[1]}` | `if not ttlBackendRepresentable(ARGV[1]) then return redis.error_reply(` | `wide` / `incrementWatchFlagAt` |
| S23 | `WATCH_INCREMENT` | `EXPIRE` | `KEYS[2]` | `{KEYS[1], KEYS[2]}` | `if not ttlBackendRepresentable(ARGV[1]) then return redis.error_reply(` | `second` / `incrementWatchFlagAt` |
| S24 | `BOUNDED_SNAPSHOT_PLAN` | `EXPIRE` | `setKey` | `{setKey}` | `if plan.initialize then` | `wide` / `addDeviceCapPairAt` |
| S25 | `BOUNDED_SNAPSHOT_PLAN` | `EXPIRE` | `metaKey` | `{setKey, metaKey}` | `if plan.initialize then` | `second` / `addDeviceCapPairAt` |
| S26 | `ROTATED_SNAPSHOT` | `EXPIRE` | `KEYS[1]` | `{KEYS[1]}` | `if staleCurrent then redis.call('DEL', KEYS[1], KEYS[2]) end` | `wide` / `addDistinctAcrossRotationAt` |
| S27 | `ROTATED_SNAPSHOT` | `EXPIRE` | `KEYS[2]` | `{KEYS[1], KEYS[2]}` | `if staleCurrent then redis.call('DEL', KEYS[1], KEYS[2]) end` | `second` / `addDistinctAcrossRotationAt` |
| S28 | `ROTATED_SNAPSHOT` | `EXPIRE` | `KEYS[1]` | `created` | `if staleCurrent then redis.call('DEL', KEYS[1], KEYS[2]); staleCurrent` | `limited` / `addDistinctBoundedAcrossRotationAtMilliseconds (previous generation present)` |
| S29 | `ROTATED_SNAPSHOT` | `EXPIRE` | `KEYS[2]` | `created` | `if staleCurrent then redis.call('DEL', KEYS[1], KEYS[2]); staleCurrent` | `second` / `addDistinctBoundedAcrossRotationAtMilliseconds (previous generation present)` |
| S30 | `ROTATED_SNAPSHOT` | `PEXPIREAT` | `KEYS[3]` | `created` | `if staleBridge then redis.call('DEL', KEYS[3], KEYS[4]); staleBridge =` | `limited` / `addDistinctBoundedAcrossRotationAtMilliseconds (previous generation present)` |
| S31 | `ROTATED_SNAPSHOT` | `PEXPIREAT` | `KEYS[4]` | `created` | `if staleBridge then redis.call('DEL', KEYS[3], KEYS[4]); staleBridge =` | `second` / `addDistinctBoundedAcrossRotationAtMilliseconds (previous generation present)` |
| S32 | `LEASE` | `EXPIRE` | `KEYS[1]` | `{}` | `if current then` | `wide` / `acquireProbeLease (expired lease present)` |
| S33 | `LEASE` | `EXPIRE` | `KEYS[1]` | `{KEYS[1]}` | `else` | `wide` / `acquireProbeLease` |
| S34 | `HARD_BLOCK` | `EXPIRE` | `key` | `{}` | `if ttl < needed then` | `limited` / `blockWithCycleTracking` |
| S35 | `HARD_BLOCK` | `EXPIRE` | `KEYS[3]` | `{}` | `elseif redis.call('EXISTS', KEYS[3]) == 1 then` | `wide` / `blockWithPunishmentLifecycleTrackingAt (longer than the stored block)` |
| S36 | `HARD_BLOCK` | `EXPIRE` | `KEYS[3]` | `{KEYS[3]}` | `else` | `limited` / `blockWithCycleTracking` |
| S37 | `PAUSE_READ` | `EXPIRE` | `KEYS[1]` | `{KEYS[1]}` | `if staleCurrent then redis.call('DEL', KEYS[1], KEYS[4]) end` | `wide` / `incrementWatchFlagAcrossRotationAt` |
| S38 | `PAUSE_READ` | `EXPIRE` | `KEYS[4]` | `{KEYS[1], KEYS[4]}` | `if staleCurrent then redis.call('DEL', KEYS[1], KEYS[4]) end` | `second` / `incrementWatchFlagAcrossRotationAt` |
