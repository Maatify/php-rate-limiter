# Key Scope Encoding E1 — Derivation Contract and Key Map

> **Current contract: ACTIVE under [DEC-046](decisions/DEC-046_E1_ONLY_ACCOUNT_SCOPE_KEY_ENCODING.md).** E1 is the sole account-scope derivation. G1 implementation is subject to independent Lead review; no release acceptance is claimed. The opt-in implementation accepted in #295 is historical evidence retained in §12.
>
> [KEY_STRATEGY](KEY_STRATEGY.md) remains the normative owner. There is no encoding selector or injected deriver.

## 1. Purpose, scope and non-goals

**Gap (merged study #290).** The V2 writer and operational reader built the account-bearing key preimages by joining
fields with `:` (for K4: `{policy}:rate_limiter:{k4|k4p}:v2:{env}:{accountId}`). `environmentScope` accepts any
non-blank string and `accountId` any non-empty string, so two different `(env, accountId)` pairs can produce the same
preimage, hence the same HMAC and the same stored key. The study proved this from source and did not run any state under
ambiguous scopes; this document does not either.

**E1 is the sole key derivation** for the account-bearing identities in §5 (Set A). It fixes three things and nothing else:

1. the byte encoding of the HMAC input (§3);
2. the HMAC key domain (§3.5);
3. the physical storage identity of the keys it produces (§6).

**Non-goals (each is a separate decision, §8 and §10):** reading V2 state from E1, seeding, the window policy, a migration
or cut-over procedure, changing any score, budget, cycle, TTL or rotation rule, K1–K3, IPv6-adaptive state,
IP/UA correlation, simple fixed-window throttling, the admission layer, the circuit breaker.

## 2. Terms (three different "versions")

| Term | Meaning | Where |
|---|---|---|
| **Store layout `v1`** | the physical prefix `maatify:rate-limiter:v1` | `RedisFullCapabilityStore::PREFIX` (`src/Repository/Redis/RedisFullCapabilityStore.php:88`); physical key built at `:3135-3138` as `PREFIX:sha256(namespace):family:sha256(logical)` |
| **Derivation `V2`** | the literal `v2` in today's account preimages | `EvaluationPipeline.php:3102`, `RateLimitOperationalReader.php:452` |
| **Encoding `E1`** | this contract | this document |

`v1` and `V2` are unrelated numbers. Separately, the code calls the previous key-secret generation `keysV1` / `previousKey`;
"Previous" in this document always means that generation (KEY_STRATEGY §4.3), never the V2 derivation.

## 3. Derivation contract

### 3.1 Inputs

| Input | Source | Presence rule (unchanged from V2) |
|---|---|---|
| `purpose` | the registry in §3.4 | always |
| `policy` | `BlockPolicyInterface::getName()` | always (the package does not validate its characters) |
| `env` | `RateLimiterConfig::environmentScope()` | non-blank, accepted as configured (no trim, no normalization) |
| `account` | `RateLimitContextDTO::$accountId` | present only if non-empty (`IdentityPresence::account`, `"0"` is present); otherwise no key |
| `material` | the K5 material (`deviceIdHash ?? fingerprintHash`, §3.4) | present only if non-empty; otherwise no key |
| `secret` | `keySecret` (Current) or `previousKeySecret ?? keySecret` (Previous) | non-blank |

Every field is an **opaque byte string**: encoded as given, with no case folding, trimming, Unicode normalization or
character restriction. Lengths are in **bytes** (`strlen`), never characters.

### 3.2 Wire format of the HMAC input

```
preimage = MAGIC ‖ u32be(N) ‖ enc(f₁) ‖ enc(f₂) ‖ … ‖ enc(f_N)
enc(f)   = u32be(strlen(f)) ‖ f
MAGIC    = "maatify.rate-limiter.scope.e1" ‖ 0x00         (30 bytes)
u32be(n) = pack('N', n)                                   (4 bytes, big-endian, unsigned)
```

- `f₁ = purpose`, `f₂ = policy`, `f₃ = env`, `f₄ = account`, `f₅ = material` (only for the purposes that have one).
- `N` is the field count the registry fixes for the purpose. The deriver rejects a different count.
- `pack('N', strlen)` is the primitive KEY_STRATEGY §4.7 already locks for simple fixed-window keys, so E1 does not
  introduce a second length convention.
- **Length bound.** `u32be` represents `0 … 2^32 − 1` only, and `pack('N', n)` silently reduces a larger `n` modulo `2^32`,
  which would let two different fields share a prefix. The deriver therefore **refuses** any field whose `strlen` exceeds
  `0xFFFFFFFF`, with a `RateLimiterException` raised **before** `pack()`, before any HMAC and before any store command; it
  never truncates, clamps or hashes the field instead. The bound is reachable only on 64-bit PHP (a 32-bit `strlen` cannot
  exceed `2^31 − 1`, so the check never fires there) and the check is performed on both widths so one rule holds everywhere.
  The refusal is part of the contract; a smaller practical cap on `env`/`accountId` is not proposed here and would be a
  separate Owner decision.
- The encoding is **injective** on tuples of byte strings **within the bound above**: `MAGIC` and `N` are constants for a
  purpose, and each field is read as exactly the number of bytes its length prefix states, so no content (`:`, NUL,
  multibyte, empty) can move a boundary. Two different `(purpose, policy, env, account[, material])` tuples therefore never
  give the same preimage. This is a statement about the **preimage**, and it is unconditional. Distinct preimages giving
  distinct *keys* (and a key separated from every other key space) is **not** unconditional: it rests on the collision
  resistance and PRF security of HMAC-SHA256 under the secret. §3.5 and the storage identity (§6) separate key spaces on
  that cryptographic assumption, not by construction. The acceptance evidence for the injectivity claim is §9, AC-2.

### 3.3 Optional values

**No field of a preimage is optional.** An absent input is never encoded as an empty field or a placeholder:

- a missing `account` or `material` means the derivation returns *no key* (`null`), exactly as `buildKeys()` does today
  (`EvaluationPipeline.php:3108-3109`);
- a variant that exists only in some situations is a **different purpose token** (`k4p` versus `k4`), never a flag
  field. This keeps the proven-lane (`P`) families distinct from the unproven ones, as DEC-026 requires.

A zero-length `policy` or `env` is encodable (`u32be(0)`) and stays injective; E1 adds no new refusal for it.

### 3.4 Purpose registry

The token is the existing V2 key type or auxiliary purpose, so the same identity keeps the same name. `N` counts the
purpose itself.

| Purpose token | N | Fields after `purpose` | V2 identity it replaces |
|---|---|---|---|
| `k4`, `k4p` | 4 | policy, env, account | `…:{k4\|k4p}:v2:{env}:{account}` |
| `k5`, `k5p` | 5 | policy, env, account, material | `…:{k5\|k5p}:v2:{env}:{account}:{material}` |
| `microcap.k5`, `microcap.k5p` | 5 | policy, env, account, material | `…:microcap:{k5\|k5p}:v1:{env}:{account}:{material}` |
| `budget_cooldown`, `budget_cooldown_p` | 4 | policy, env, account | `…:{budget_cooldown[_p]}:v1:{env}:{account}` |
| `aux.last_missing_fp`, `aux.flood_stage` | 4 | policy, env, account | `…:aux:{purpose}:v1:{env}:{account}` |
| `aux.anti_equilibrium`, `aux.anti_equilibrium_p` | 4 | policy, env, account | same |
| `aux.known_device_allowance`, `aux.known_device_allowance_p` | 4 | policy, env, account | same |
| `aux.anti_equilibrium_device`, `aux.anti_equilibrium_device_p` | 5 | policy, env, account, material | `…:aux:{purpose}:v1:{env}:{account}:{material}` |

`material` is the one the pipeline selects today (`k5Material()`: without a lane the fingerprint hash; with a lane the
instance-id component when present, else the fingerprint hash; DEC-026, KEY_STRATEGY §3.5). E1 does not hash it again and
does not assume it is hex.

A purpose absent from this table is not E1. Adding one is a change to this contract, not a local choice.

### 3.5 HMAC key domain

```
subkey = HMAC-SHA256(key = secret, message = "maatify.rate-limiter.key-domain.e1")      (32 raw bytes)
digest = lowercase-hex( HMAC-SHA256(key = subkey, message = preimage) )                (64 hex characters)
```

The domain label is unique in the package (the only other domain labels in `src/` belong to the admission credential codec
and use the binding key, not the key secret). A different secret generation gives a different subkey, so Current and
Previous stay different identities, and rotation is unchanged: Previous uses `previousKeySecret ?? keySecret`.

The domain separates E1 digests from V2 digests **even if a V2 text ever equalled an E1 preimage**; it does not repair V2
ambiguity and does not tell storage apart. The encoding (§3.2) removes ambiguity; the domain (§3.5) separates the key
space; the storage identity (§6) separates the physical space. They are three layers with three different jobs.

### 3.6 Output: the E1 logical key

```
E1Key = "e1." ‖ digest              (67 ASCII characters: "e1." then [0-9a-f]{64})
V2Key = digest                     (64 characters, today)
```

The pipeline and the stores pass keys around as plain strings, and some of them must survive as strings: the
distributed-account snapshot returns K5 keys as set members that are later blocked directly
(`EvaluationPipeline.php:777-785`, where each snapshot member becomes the `key` of a persisted block), and the hard-block
script receives the logical keys as arguments (`HARD_BLOCK` does not read them). The `e1.` prefix is the **textual form**
of an E1 key: it makes an E1 digest distinguishable from a V2 digest in logs, snapshots and members, and it is what a
snapshot member is stored as.

**The prefix is not a routing signal.** `RateLimitStoreInterface` and the correlation store accept any `string`, and the
package reserves no `e1.` prefix, so an existing direct caller may already pass a key that starts with `e1.` (or equals the
64-hex shape). A store that decided *by the shape of the string* would move or refuse such a key after a plain package
update. Storage identity is therefore carried **explicitly** (§6.2), from the derivation to the store, and the string shape
never selects a layout in a store entry point. A search of `src/` finds no length, hex or `ctype_xdigit` test on a key, and
no member-size limit in the distinct-set scripts; the acceptance evidence for this is AC-5/AC-6, not the search.

### 3.7 Synthetic vectors

Reference construction (illustrative, not package code; the secrets are fixtures, never real values):

```php
function e1_preimage(string ...$fields): string {
    $out = "maatify.rate-limiter.scope.e1\x00" . pack('N', count($fields));
    foreach ($fields as $f) { $out .= pack('N', strlen($f)) . $f; }
    return $out;
}
function e1_key(string $secret, string ...$fields): string {
    $sub = hash_hmac('sha256', 'maatify.rate-limiter.key-domain.e1', $secret, true);
    return 'e1.' . hash_hmac('sha256', e1_preimage(...$fields), $sub);
}
```

Fixtures: `S = "synthetic-key-secret-not-for-production"`, `SP = "synthetic-previous-secret-not-for-production"`.
Computed on PHP 8.5.13 (64-bit host), PHP 8.4.27 `PHP_INT_SIZE=8` and PHP 8.4.27 `PHP_INT_SIZE=4` (`linux/386`), with
byte-identical output on all three.

| Case | Secret | Fields (purpose, policy, env, account[, material]) | `E1Key` |
|---|---|---|---|
| K4 | S | `k4`, `login_protection`, `prod-eu`, `acct-0001` | `e1.9678f6f2458e4fc59a0b91701343b4a1e07c81144d9e40025bb2e9aa2b825fa7` |
| K4P | S | `k4p`, same | `e1.38940a4b90349291e802f881e122cf53496398db08844cb5760310593e1c9b5a` |
| K4 Previous | SP | `k4`, same | `e1.edfab467ae05965b5e16781013c16e3a20fc6b51bd3e065ba08a3aaf88d0e7ae` |
| K5 | S | `k5`, same, material `f00dcafe` | `e1.493ca457ebe7b232f740cd9d57ad046e0185e2e3ebe1ea5bb47ddd58d23193a4` |
| K4, bytes not characters | S | `k4`, `login_protection`, `prod:eu`, `ünï:42` (U+00FC, U+00EF; 8 bytes) | `e1.9692c65562144f66c8d9af55a9adcd519f6d623ec3a3cea8782a3f6e52896de8` |
| Cooldown | S | `budget_cooldown`, `otp_verification`, `prod-eu`, `acct-0001` | `e1.3ff1b220ae56440f6a2b8215e5b0af16edc94444d315450f3620747e67ea80ca` |
| Micro-cap | S | `microcap.k5`, `login_protection`, `prod-eu`, `acct-0001`, `f00dcafe` | `e1.111e12c1ee52d71bb982ff4dc88ac854b9d6c47f4b9664e823d47ea167ef7eb8` |

Preimage of the K4 row (hex), to check the framing by hand: `MAGIC` = `6d6161746966792e726174652d6c696d697465722e73636f70652e6531` + `00`;
`N` = `00000004`; then `00000002 6b34` (`k4`), `00000010 6c6f67696e5f70726f74656374696f6e` (`login_protection`),
`00000007 70726f642d6575` (`prod-eu`), `00000009 616363742d30303031` (`acct-0001`). The K4 and K4 Previous rows have the same
preimage and differ only by secret. The vectors are inputs to the acceptance tests (§9, AC-1); they are fixtures, not a
claim about any deployment.

## 4. One derivation source and closed composition

The final internal `E1AccountScopeKeyDeriver` serves writer and reader, each constructed from its own validated environment. It is not injected or selected. Shared implementation, framing, vectors and Current/Previous rules are the contract; a shared immutable object instance is not required.

The Pipeline creates its own BudgetTracker and AntiEquilibriumGate on the exact validated E1 handles and EphemeralBucket on the correlation root. Advanced construction supplies the existing clock dependencies explicitly by role: the budget clock is required, and Gate/Bucket clocks may be null to retain backend-time operations. Builder passes its request clock to all four clock roles. Standalone helper APIs and clock authority do not change.

`E1StoreBinding` is an internal closed capture, not a Host option: it obtains a distinct stable view, validates idempotence and interface preservation, and retains those handles. Builder transfers this closed capture to the reader internally so binding/adoption and execution use the same objects without another adapter lookup. A binding for another root is rejected. No public layout/derivation extensibility is introduced.

Both root and view handles enter resource collection before binding/adoption. The official Redis views report the same root; arbitrary Host resource-report methods remain untrusted. Custom adapter storage and transaction semantics require conformance proof, not merely `instanceof`.

Processing preflight uses the actual targets before backend access; Host policy getters run under the ownership/crossing boundary and their immutable results are retained through evaluation and retries. A lifecycle claim uses its existing capability and identity inputs, not a nonexistent policy object.

## 5. Scope bound to the source

Verified against `main@35398c11` (tree `c06f12ca…`). "Writer" is `EvaluationPipeline`, "Reader" is
`RateLimitOperationalReader`. Store families are those of `RedisFullCapabilityStore::key($family, …)`; all twelve are:
`block`, `budget`, `circuit`, `cycle`, `distinct`, `distinct-meta`, `pause`, `probe`, `reentry-claim`, `score`, `watch`,
`watch-meta`.

### 5.1 Set A — re-derived by E1 (account-bearing preimages)

The physical key of every row is `maatify:rate-limiter:e1:{sha256(namespace)}:{family}:{sha256(E1Key)}` (§6) for every account-scope runtime and reader. The V2 column below identifies the historical preimage replaced; it is not an available runtime path.

| Identity | Inputs | Logical key today → E1 | Families written / read | Writer | Reader | Rotation, atomicity, expiry path |
|---|---|---|---|---|---|---|
| **K4**, **K4P** | policy, env, account | `…:{k4\|k4p}:v2:{env}:{account}` → `k4`/`k4p` | `score`; `block`, `cycle`, `pause` (hard-block history); `reentry-claim` (DEC-007 claim); `budget` (K4 budget epoch, keyed by the same K4 key) | `buildKeys` `:3108`; used by `fetchScores`, `persistWinningCandidates` (`:2234`), the budget increment (`:1972`) and `claimPostPunishmentReentry` (`:163`) | `buildKeys` `:463`; `store->get` (score), `operationalBlock`, `readDecayPauseState`, `operationalBudget` | Current writable, Previous read-only. DEC-007 lifecycle scripts take both generations (`LIFECYCLE_READ`, `LIFECYCLE_MUTATE` with the `reentry-claim` key, `HARD_BLOCK` over cycle, block, pause and score, store `:2744-2753`, `:2810-2817`, `:2886-2890`, `:2955-2962`, `:3003`); budget seeds Previous→Current only through `BudgetSeedStoreInterface`; no `max()` merge |
| **K5**, **K5p** | policy, env, account, material | `…:{k5\|k5p}:v2:{env}:{account}:{material}` → `k5`/`k5p` | `score`, `block`, and `cycle`/`pause` when a K5 hard block records history (the same `blockWithCycleTracking` path takes any key) | `buildKeys` `:3109` | `buildKeys` `:466`; same reads | as K4; Previous exists when the secret or the material rotated |
| **Micro-cap** | policy, env, account, material | `…:microcap:{k5\|k5p}:v1:{env}:{account}:{material}` → `microcap.k5[p]` | `budget` | `:1952-1958` (V2 and Previous), through `incrementBudgetAcrossRotation` | `microCapKey` `:374-383` | cumulative budget state: Current authoritative, atomic seed of a valid Previous, never `max()` (KEY_STRATEGY §4.5.2) |
| **Budget cooldown** | policy, env, account | `…:{budget_cooldown[_p]}:v1:{env}:{account}` → `budget_cooldown[_p]` | `score` family counter used as an atomic fixed-TTL marker (`increment`, issuance only when the returned value is 1) | `budgetCooldownKey` `:2622-2628`; acquisition `:2585-2625` | `budgetCooldownKey` `:386-392`; `store->get` | Current, then Previous read-only; acquisition must stay one atomic `increment` (KEY_STRATEGY §4.5.1) |
| **`aux.last_missing_fp`** | policy, env, account | `…:aux:last_missing_fp:v1:…` | `score` (`replaceScore`/`get`/`set`) | `auxiliaryAccountKey` `:2683-2692`; use `:1784-1805` | none | marker read and written in one atomic step (DEC-031); Previous read-only fallback |
| **`aux.flood_stage`** | policy, env, account | `…:aux:flood_stage:v1:…` | `watch`, `watch-meta` | `:399-410`, `:441` | none | flag with 900 s TTL; Current only is written |
| **`aux.anti_equilibrium[_p]`** | policy, env, account | `…:aux:anti_equilibrium[_p]:v1:…` | `watch`, `watch-meta` | `:477-478`, record `:544` | none | threshold is Current + active Previous; `AntiEquilibriumGate` |
| **`aux.known_device_allowance[_p]`** | policy, env, account | `…:aux:known_device_allowance[_p]:v1:…` | `budget` | `:1774-1775` | none | fixed 24 h epoch counter, seeded across rotation (KEY_STRATEGY §4.6) |
| **`aux.anti_equilibrium_device[_p]`** | policy, env, account, material | `…:aux:anti_equilibrium_device[_p]:v1:{env}:{account}:{material}` | `watch`, `watch-meta` | `deviceAntiEquilibriumKeys` `:2648-2679` | none | Previous exists only when the secret or the material rotated |

The reader reads K1–K5 scores and blocks, the pause state, the K4 budget, the micro-cap and the cooldown. It does not read
the `aux` families or the `reentry-claim`; they have a writer only. (Request path: all writes of one request go through the
overlay and one commit, DEC-037; the overlay and commit receive physical keys and are indifferent to how they were built.)

### 5.2 Set B — derivative: identity follows the E1 key, derivation unchanged

These state keys are hashes **of** a K4/K5 key. E1 does not re-derive them; because their input changes, an E1 runtime
gets different (fresh) keys for them automatically, and they stay physically under `v1`.

| Identity | Input that carries the account | Source |
|---|---|---|
| distributed-account device set and watch | `{prefix}:scope:{K4}`; members are K5 keys that are later blocked directly | `EvaluationPipeline.php:836-880`, `:732-790` |
| distributed-account occurrence history | `{prefix}:scope:{K4}` | `:882-915` |
| account correlation anchor (the `device_cap_account` observation and other account-anchored correlation) | `K4 ‖ ":" ‖ K1` | `accountAnchor` `:1573-1580`, used at `:280-289` |

Decision D-2 (§10) confirms this treatment. Their own preimages keep the `{policy}:…:{env}:` join, which is outside the
account boundary studied in #290 and is not part of this contract.

### 5.3 Set C — inspected, outside E1, physical keys unchanged

| Family | Why outside | Source |
|---|---|---|
| K1, K2, K3 | network and device inputs, not accounts (study §3.3); a separate decision | `EvaluationPipeline.php:3105-3107`, reader `:456-460` |
| IPv6-adaptive hierarchy, churn anchor, credential-spray | network or subject inputs | `:1124`, `:1157`, `:1251`, `:1718-1731` |
| Simple fixed-window throttling | already length-prefixed (`pack('N')`, KEY_STRATEGY §4.7) | `FixedWindowSimpleRateLimiter.php:307-325`, `SimpleRateLimitOperationalReader.php:175-193` |
| `circuit`, `probe` | keyed by policy name | store `:2650`, `:2725` |
| Admission layer (`adm:*`) | its own derivation, §5.4 | `AdmissionRuntime.php:83-86`, `:537-545`; `RedisAdmissionOperations.php:605-635`, `:860` |
| `LocalFallbackLimiter` | in-process, no Redis | `LocalFallbackLimiter.php:136-159` |

### 5.4 Adjacent findings from this mapping (not E1, not fixed here)

1. **Admission keys use a NUL join.** `AdmissionRuntime::key()` and `epochKey()` join `env`, group, purpose, value and label
   (and `env`, `'binding_epoch'`, `accountId`) with `"\0"` and no length prefix (`:537-545`). `env` and `accountId` accept
   NUL, so the same class of ambiguity as #284 exists there. It is a different surface (admission opt-in policies) and a
   separate decision.
2. **Documentation and source disagree on `binding_epoch`.** KEY_STRATEGY §4.6 says its preimage is
   `{budget group}:rate_limiter:aux:binding_epoch:v1:{env}:{accountId}`; the source hashes `env ‖ 0x00 ‖ "binding_epoch" ‖ 0x00 ‖ accountId`
   with no budget group (`AdmissionRuntime.php:544`). Not changed here; recorded for the Owner.

## 6. Storage identity

### 6.1 Why the prefix cannot simply change

The prefix `maatify:rate-limiter:v1` is shared by **every** family, and the same family holds both in-scope and out-of-scope
keys (`score` holds K1 and K4; `block` holds K1–K5). Replacing the constant would move K1–K3, correlation, simple throttling
and the admission layer too. E1 therefore separates storage **per key**, not per prefix constant.

### 6.2 Rule

```
E1 physical key = "maatify:rate-limiter:e1:" ‖ sha256(namespace) ‖ ":" ‖ family ‖ ":" ‖ sha256(E1Key)
V2 physical key = "maatify:rate-limiter:v1:" ‖ sha256(namespace) ‖ ":" ‖ family ‖ ":" ‖ sha256(logical)    (unchanged)
```

**The layout is carried explicitly; it is never inferred from the key string.** `RateLimitStoreInterface::increment/get/set/block`
and the correlation store accept any `string`, and the package reserves no `e1.` prefix (`src/Repository/RateLimitStoreInterface.php:39-62`
on `main@35398c11`). A direct caller may already pass `e1.…`-shaped or 64-hex keys today. Routing by string shape would
change or refuse such a call after a plain package update, even with E1 unselected. The contract is therefore four
invariants, implemented by the approved layout-bound view (D-4):

1. **Plain strings are untouched.** For every `string` passed to an existing store method, in every family, including one
   that begins with `e1.`, equals `e1.` plus 64 hex characters, or is malformed in any way, the physical key is the
   `v1` key of `main@35398c11` byte for byte, and no refusal is added. This holds with E1 selected and unselected.
2. **E1 identity travels with the key.** In an E1 runtime, the pipeline routes the derived Set A key through the
   layout-bound view. That explicit carrier selects the `e1` physical prefix. The textual form `e1.‹digest›` is retained
   for logs, snapshots and members.
3. **Carrier creation is closed.** An E1 carrier is created only by the E1 derivation component, and by the E1 runtime's own
   re-wrapping of a member it previously stored (a snapshot member that is later blocked,
   `EvaluationPipeline.php:777-785`). That re-wrap validates the exact grammar `^e1\.[0-9a-f]{64}$` and throws on anything
   else. It lives inside an E1-selected runtime, so a legacy caller can never reach it; no store entry point parses a string
   to find a layout.
4. **Foreign families are refused.** An E1 carrier given to a family outside
   `{score, block, budget, cycle, pause, reentry-claim, watch, watch-meta}` (the eight families that hold Set A keys;
   `distinct` and `distinct-meta` hold only Set B hashes) throws `RateLimiterException` before any Redis command. It is a
   defect in the E1 runtime, not a legacy path, because only the E1 runtime can hold a carrier.

**The two layouts must be one transaction.** On `main@35398c11` the held request state (`$this->requestState`), the
exclusive request ownership of DEC-041 (`guarded()`) and the evaluation in the overlay (`eval()` → `evalInRequest()`) live
**inside the store object**, and `transactRequest()` commits that one state. Sharing a Redis executor between two store
objects does not join them: each would hold its own overlay and its own ownership, and a request that writes a K1 key
(`v1`) and a K4 key (`e1`) would commit twice, so it could be saved in one layout and refused or lost in the other. That
would break DEC-037's all-or-none save for exactly the requests E1 is for.

D-4 approved **(a)**, the **view bound to the E1 layout of the same store instance** exposed by
`E1KeyspaceStoreInterface::e1Keyspace()`. The root and view share `requestState`, the ownership guard and
`transactRequest()`. The pipeline selects the layout per key. The design also considered **(b)**, a typed key object
with a new method surface, because the existing methods accept `string` under `strict_types`. AC-9 verifies the shared
transaction and ownership on the accepted view.

Consequences that the acceptance tests check (§9, AC-5): with the same inputs, every physical key of §5.3 families and every
K1–K3 key is identical in an E1 runtime and a V2 runtime; every Set A key exists only under `…:e1:…`; no Set A key exists
under `…:v1:…`; the Set B keys differ by design (§5.2) and stay under `v1`; and a direct-call differential (invariant 1)
shows that plain strings, including `e1.`-prefixed and 64-hex ones in each of the ten families, keep the physical keys of
`main@35398c11` and are not refused. A policy name can never route a key to `e1`: a string never selects a layout, and
`circuit`/`probe` are outside the family set.

### 6.3 Consequences for the Host

- **ACL.** A Redis account restricted to `~maatify:rate-limiter:v1:*` must be granted the `e1` key prefix before E1 is
  selected, **for reads as well as writes**. Two different guards act, and they must not be confused:
  - **Entry guard (before the save).** A refusal of a read command, of `EVAL` itself or of the key scope happens before any
    write and leaves everything unchanged (DEC-042, Context). With only the `v1` grant, an E1 request is refused here, at
    the read or script entry, and never reaches `commit`. This is not the DEC-042 preflight.
  - **Save guard (DEC-042).** Only when the account can read the E1 keys and run the evaluation, but lacks a write command
    or permission of the plan, does `commit` check its actual write plan with `redis.acl_check_cmd` after the state
    comparison and before the first write; the refusal is `NOPERM request commit: …`, raised before any write, deletion or
    expiry change, with the keyspace unchanged.
  - **Direct scripts.** A refused **expiry** command is classified `NOPERM` and the script deletes the keys *that call
    created*; it does not restore a key the branch had already deleted or rewritten, and an unchanged keyspace is evidenced
    only for the states DEC-043 measured (DEC-043, Decision 2). E1 makes no wider claim for direct calls.
- **Inventory and cleanup.** The two prefixes make "is this key E1 or V2" a prefix test for scans, metrics and operational
  tooling.
- **Single node.** The same Redis 7+, single-node scope as DEC-037/042 applies; Redis Cluster is not studied.
- **Custom stores.** A store or correlation store that is not E1-aware stores whatever string it receives under its own
  layout. Selecting E1 therefore requires an E1-capable store, and composition fails early otherwise (§8).

## 7. Rotation, expiry and atomicity

E1 changes **only** the derivation and the prefix of the §5.1 keys. It does not touch a TTL, a script, a score formula, a
cycle rule, a lifecycle rule or a rotation rule. Therefore, for E1 runtimes:

- **Current/Previous** are exactly KEY_STRATEGY §4.3 to §4.3.3 applied to E1 keys: writes go to Current; Previous is
  read-only and read only when Current is absent (or by the existing per-family rule); a valid Previous budget is seeded
  atomically into Current; `max()` and Cartesian merges stay forbidden for budget state; at most one Previous generation.
  A Previous E1 key is an E1 key derived with the previous secret or material. **A V2 key is never a Previous generation of
  an E1 key** and is never read.
- **Expiry.** The **logical** deadline and the **physical** lifetime are different things and are evidenced separately
  (DEC-016: logical deadlines are the caller's time plus a TTL; physical key lifetimes stay relative on the Redis clock;
  the request overlay and commit use Redis `TIME` for physical expiry bookkeeping, DEC-037). E1 changes neither. Physical
  evidence is taken from outside Lua with `PEXPIRETIME`; logical evidence is the stored deadline value read with `DUMP`
  or the family's own read. Three cases:
  1. **Logical deadline from caller-supplied time.** With the same injected time and TTL the stored `expiresAt` is
     deterministic: the E1 key and the V2 key are compared **exactly** across layouts. This says nothing about the
     physical TTL of the same key.
  2. **Physical expiry that the path must preserve** (an operation that keeps the physical lifetime the key already had,
     such as the request save of an unchanged lifetime, which is the path #287 measured). Compared **literally, in one
     run**: `PEXPIRETIME` before the operation equals `PEXPIRETIME` after it. The literal comparison is attached **only**
     to a path that actually pins the physical expiry; it is not applied to a path whose expiry is re-derived from a TTL.
  3. **New physical TTL built from a relative TTL on the Redis clock.** The absolute value depends on the `TIME` of that
     run and is **not** compared between a V2 run and an E1 run. Each run samples Redis `TIME` immediately before and after
     the operation and asserts `PEXPIRETIME − TIME` lies inside the bracket the TTL implies; parity is judged on that
     delta and on the recorded bracket width, never on absolute timestamps.

  **The request-path dependency [#287](https://github.com/Maatify/php-rate-limiter/issues/287) was resolved in `main@4c523096`.**
  [DEC-045](decisions/DEC-045_REQUEST_SAVE_REFUSES_AN_EXPIRY_LUA_CANNOT_READ_EXACTLY.md) applies to both layouts.
  A writing request is refused before its first write when a key it read has an expiry Lua cannot compare exactly.
  Below 2^53 ms, a path that preserves the physical expiry saves it literally.
- **Atomicity.** Every write remains one Lua script on the direct path or one overlay commit for a request (DEC-037, with
  the ACL preflight of DEC-042 and the exclusive ownership of DEC-041). The overlay requires every key in `KEYS`; E1 keys
  are declared like V2 keys. One request commit (one per attempt; a conflicting attempt saves nothing and the next attempt commits again, DEC-037) saves keys of both layouts together (K1 under `v1`, K4 under `e1`); this
  needs no new rule because the commit and the scripts treat physical keys as opaque.
- **Time authority.** Unchanged: caller time where the capability receives it, Redis `TIME` only where DEC-016 allows it.

## 8. Compatibility boundaries

1. E1 is mandatory for Set A; V2 implementation, enum, selector and optional deriver injection are removed. This is a breaking pre-Stable change.
2. `build()` requires E1-capable rate-limit and correlation roots, with all capabilities actually used preserved on their views. A view passed as root, an unstable/non-idempotent pair or a missing required capability fails before package backend commands. `e1Keyspace()` itself must be pure; arbitrary adapter code is not sandboxed.
3. `buildOperationalReader()` requires E1 and read-only rate-store/view capabilities, without correlation or mutation-only requirements. The advanced reader retains DEC-032's separately documented low-level fallback for an E1-capable store without read-only reads.
4. Start new traffic in an isolated namespace/environment after capability/ACL setup. The package does not discover old workers or prove the namespace empty.
5. Moving existing V2 traffic starts fresh Set A and derivative Set B identities, losing visibility of blocks, scores, budgets, cooldowns and affected history. No live upgrade, dual-read, cross-encoding seed, mixed workers or rollback is supported. No Redis data is deleted. Preserved E1 bytes alone do not qualify a deployment update.
6. Current/Previous always means secret/material generations **inside E1**. Their atomic budget seed and lifecycle continuity remain; it is not a migration between encodings.
7. Adjacent Admission NUL joins, the binding-epoch contract discrepancy and other multi-field framing defects remain release blockers tracked on #284. G1 does not fix them or authorize a wider state reset.

## 9. Historical opt-in acceptance criteria

The following criteria describe the accepted opt-in implementation of DEC-044, now superseded. G1 replaces selection/V2-default criteria with the closed-composition and E1-only gates in §13; the storage, expiry, rotation and atomicity regressions remain required. See §12 and [PR #295](https://github.com/Maatify/php-rate-limiter/pull/295) for the observed coverage and its
limits. Redis evidence uses the repository's pinned Redis 7.0.15 service and is taken from outside Lua
(`PEXPIRETIME`, `DUMP`, `SCAN`) for **PHP 32-bit and 64-bit**. The runs record `PHP_INT_SIZE` and use synthetic data.

| ID | Evidence |
|---|---|
| AC-1 | The §3.7 vectors, the golden V2 values from `main@35398c11`, and the hex preimage reproduce byte for byte on both widths. |
| AC-2 | Injectivity of the E1 encoding, **per integer width**. Both widths: a generated corpus over fields with `:`, NUL, multibyte, empty and long values; a decoder that parses every preimage back to the exact tuple; distinct tuples never give equal preimages or equal keys; the shared vectors of §3.7 identical on both. The corpus and the key comparison are evidence **on the sample**, not a proof; the proof is the length-prefix argument of §3.2. Length bound through a seam that supplies the length (no `2^32`-byte string is allocated): **PHP64** tests `0`, `1`, `2^24`, `0xFFFFFFFE`, `0xFFFFFFFF` (all encode, prefix equals the supplied length), `0x100000000` and `PHP_INT_MAX` (refused before `pack()`, before any HMAC and before any store command). **PHP32** tests the representative lengths `0`, `1`, `2^24` and `PHP_INT_MAX = 2^31 − 1` (all encode) and records that the refusal is unreachable on that width because `strlen` cannot exceed `PHP_INT_MAX`. `PHP_INT_SIZE` is asserted first and the raw output kept. |
| AC-3 | Writer and reader share one derivation source: a parity matrix (lane U/P, with and without an instance id, one secret and a rotation with Current and Previous) for every §3.4 purpose, plus a source guard that no `hash_hmac` of an account-bearing preimage remains outside the component. Generalises `FamilyReaderParityTest`. |
| AC-4 | Rotation: Current-only, previous secret, previous material, both, equal-key de-duplication; writes only to Current; Previous never written; budget seeding atomic; no `max()`; **a V2 key for the same account is never read and no V2 key is written for a Set A identity by an E1 runtime**. |
| AC-5 | Stability and separation: differential run of the same traffic on a V2 runtime and an E1 runtime in the same Redis; physical keys, values and expiries of §5.3 families and K1–K3 are identical; Set A keys exist only under `…:e1:…` and never under `…:v1:…`; Set B differences are exactly the documented set; an E1 carrier in a foreign family throws before any command; **direct-call differential**: plain strings, including `e1.`-prefixed, exact `e1.`+64-hex and malformed ones, passed to the existing store methods in each of the ten families, give the `main@35398c11` physical keys and are not refused, with E1 selected and unselected. |
| AC-6 | Expiry, rotation and lifecycle on E1 keys: evidence in both encodings for DEC-003 cycle and pause, DEC-007 lifecycle and claim, budget rotation continuity and device-generation rotation, with expiry compared by `PEXPIRETIME` **per the three cases of §7** (logical deadline from caller time: stored `expiresAt` exact across layouts; preserved physical expiry: literal before/after in one run, only on a path that pins it; new Redis-clock TTL: bracketed by `TIME`, never absolute across runs); the request path as stated in §7 (DEC-045). |
| AC-7 | Request atomic save on E1 (DEC-037/041/042/045): conflict recalculation and the physical-expiry guard in either layout; interruption has the two outcomes of AC-9 (iii)/(iv): nothing saved if the save never reached Redis, or the whole save with an unknown outcome to the caller if only the reply was lost. ACL, two scenarios: **(i)** an account with only the `v1` grant is refused at the read/`EVAL`/key-scope entry with an unchanged keyspace (the entry guard; no `commit` is reached); **(ii)** an account that **can read** the E1 keys and run the evaluation but lacks a write command of the plan receives the DEC-042 `NOPERM request commit` from `commit`, before the first write, with an unchanged keyspace, and the same request succeeds once the grant is complete. Direct scripts: limited to DEC-043 (a refused expiry command is `NOPERM`, with its cleanup bounds). |
| AC-8 | Compatibility: with E1 unselected the full suite passes and the physical keys equal `main@35398c11`; E1 over a store without the capability fails at composition; a V2 reader cannot read E1 state and an E1 reader cannot read V2 state, each shown explicitly. |
| AC-9 | One transaction **per attempt** for both layouts. A request that **writes a V2-layout key and an E1-layout key** (for example a K1 hard-block and a K4 score) is served by one store instance. Each **attempt** issues at most one `commit`. DEC-037 still allows a conflicting attempt to be evaluated again and saved by a new attempt, so the evidence has two parts: **(i) a conflicting attempt** (a key of either layout changed between read and commit) returns the conflict and saves **none** of the request's writes in **either** layout (keyspace, values and expiries unchanged, read from outside Lua), and the request is re-evaluated within the DEC-037 limits; **(ii) the successful attempt** saves **both** layouts together, in that one `commit`. A forced `NOPERM` in each layout leaves both layouts unchanged. **Interruption has two distinct outcomes, each read from outside Lua and never conflated (DEC-037, Consequences):** **(iii) interrupted before the save reached Redis** (no `commit` delivered) leaves **none** of the request's writes in **either** layout; **(iv) Redis applied the `commit` and the reply was lost** leaves the request's writes stored in **both** layouts together (the save applies in full or not at all) while the caller sees a `BackendFailureException` with an **unknown outcome**, never a partial one, and the lost-reply contract is preserved (no re-evaluation presumes the save failed). Neither case may leave one layout written and the other not. Evidence shows a single held `requestState`, a single ownership claim (DEC-041: an independent call during the request is refused on either view) and, per attempt, a single `commit` script call, on PHP32 and PHP64. A view or key that owns separate request state fails this item. |

## 10. Historical opt-in decision register

The Lead accepted D-1 through D-6 in [PR #295](https://github.com/Maatify/php-rate-limiter/pull/295) under the Owner's delegation on [#284](https://github.com/Maatify/php-rate-limiter/issues/284). D-7
remains a separate migration decision.

| ID | Disposition |
|---|---|
| D-1 | DEC-044 is active for the encoding, key domain and storage identity of §3 and §6. |
| D-2 | Set A is the E1 scope. Set B is derivative. The families and findings in §5.3 and §5.4 stay separate. |
| D-3 | `RateLimiterBuilder::withKeyScopeEncoding()` selects the encoding explicitly once per builder. V2 is the default. |
| D-4 | Option (a) is accepted. `E1KeyspaceStoreInterface::e1Keyspace()` is the view of the same store (§6.2), retaining the plain-string and single-transaction guarantees. |
| D-5 | The Host grants read and write access to the `e1` key prefix before selecting E1. See the [Usage Guide](guides/USAGE_GUIDE.md#choosing-the-e1-key-encoding-dec-044). |
| D-6 | [KEY_STRATEGY](KEY_STRATEGY.md) and the [Package Reference](../RATE_LIMITER_PACKAGE_REFERENCE.md) incorporate the opt-in contract. The security review required by KEY_STRATEGY §10 is accepted in [PR #295](https://github.com/Maatify/php-rate-limiter/pull/295). |
| D-7 | Existing-traffic migration, V2 block reads, seeding E1 from V2, window policy and retirement of V2 remain separate. Any migration must preserve active blocks (§8). |

## 11. What this document does not establish

- It does not prove V2 collisions in any deployment; the gap is a source-level proof (#290).
- §3.7 was computed outside the package before the implementation; the implementation reproduces it byte for byte on both
  integer widths (AC-1, `tests/Unit/Service/AccountScopeKeyDeriverTest.php`).
- It does not show that any Host passes attacker-controlled account identifiers or shares one secret and namespace across
  environment scopes.
- Redis Cluster, Redis older than 7.0, non-official stores and the admission layer are not covered.
- It does not decide when, or whether, an existing deployment moves to E1.

## 12. Historical opt-in implementation record

Issue [#284](https://github.com/Maatify/php-rate-limiter/issues/284), base `main@4c523096`. The Lead accepted the implementation in [PR #295](https://github.com/Maatify/php-rate-limiter/pull/295)
at [`ccfb86964159d126d57ca34615ee28f81333ef70`](https://github.com/Maatify/php-rate-limiter/commit/ccfb86964159d126d57ca34615ee28f81333ef70).

| Item | Choice |
|---|---|
| Selection (D-3) | `RateLimiterBuilder::withKeyScopeEncoding(KeyScopeEncodingEnum $encoding)`; `KeyScopeEncodingEnum::{V2, E1}`; default `V2`. The selection is fixed by the first successful build: once a runtime or a reader has been built from a builder, selecting a different encoding on that builder throws (F295-02), so the runtime and the reader it builds always receive the same derivation instance. Only a successful build fixes it: a build that fails at any point (for example a DEC-041 provider mismatch after the derivation was created) leaves the selection free, so the Host can correct the cause and still choose either encoding. The selection also cannot change while a build is running (a Host callback that tries to switch it from inside a build is refused); it is free again only when every attempt failed. A failure never undoes a selection that an earlier or nested successful build already fixed (a reader built inside a Host callback of a build that then fails keeps its selection). |
| Capability (D-4 a) | `Repository\E1KeyspaceStoreInterface::e1Keyspace(): static`, implemented by `RedisFullCapabilityStore`. The view is a clone of the same store that points back at its root: one request state, one ownership claim, one `transactRequest()`; only `key()` differs. Idempotent. A copy of a store (`clone`) never inherits the view of the original: it creates its own, bound to the copy's own request state, so cancelling a request on a copy leaves no trace in either layout (F295-01). |
| Plain strings | The store's own methods are unchanged: a string never selects a layout (invariant 1). The view accepts only the exact grammar `^e1\.[0-9a-f]{64}$` and only the families `score`, `block`, `budget`, `cycle`, `pause`, `reentry-claim`, `watch`, `watch-meta`; anything else throws `RateLimiterException` before any command (invariants 3 and 4). |
| Derivation source | `Service\AccountScopeKeyDeriverInterface` with `V2AccountScopeKeyDeriver` (byte-identical to the shipped text and plain HMAC) and `E1AccountScopeKeyDeriver` (§3). The purpose registry is `Service\AccountScopePurpose`. Current and Previous are the same call with the previous secret and material. |
| Pipeline routing | `EvaluationPipeline` remembers, per evaluation, the account-bearing keys it derived (`deriveAccountKey()`) or adopted from stored snapshot members (`adoptStoredAccountKey()`, which re-validates the E1 grammar) and reads and writes each in its layout. K1 to K3, Set B and Set C keys stay on the store itself. The registry is reset at the start of every evaluation. |
| Builder wiring | Under E1 the budget tracker and the anti-equilibrium gate receive the E1 views of the rate-limit and correlation stores. E1 over a store without the capability fails in `build()` and `buildOperationalReader()` with a `RateLimiterException`, before any command. |
| Selection of the layout of each store call | By the key type of the evaluation (k4, k5, auxiliary, micro-cap, cooldown and the K4 budget), never by the shape of a string. |

**Not changed.** Every TTL, script, score formula, cycle, lifecycle, rotation rule and the admission layer. Set B keys differ
from V2 only because their K4/K5 input differs. The V2 account state that E1 replaces is not read, seeded or migrated.

**Evidence** (Redis 7.0.15 isolated, `PHP_INT_SIZE` 4 and 8; the PR records the raw outputs):

- AC-1, AC-2, AC-3 source guard: `tests/Unit/Service/AccountScopeKeyDeriverTest.php`.
- AC-3 parity and AC-4 rotation: the lane, lifecycle, cycle and reader-parity suites (`FamilyGuardsTest`, `FamilyLifecycleTest`, `FamilyCyclesTest`, `FamilyReaderParityTest`: lane U and P, with and without an instance id, one secret and a rotation, K4/K5/micro-cap/cooldown/allowance/flood/last-missing-fingerprint/anti-equilibrium) run unchanged under both encodings; `KEY_SCOPE_ENCODING=e1` selects E1 for the test laboratory and CI runs them both ways. Their expected keys come from the same single derivation component through the laboratory's oracle helpers, so a drift between writer and reader fails them under either encoding.
- AC-4 to AC-9 on Redis: `tests/Integration/Redis/KeyScopeE1RedisTest.php` (separation and Set B families, reader parity and no cross-reads, Current/Previous rotation, plain strings and the view, capability failure, one commit per attempt, conflict in either layout, interruption before the save and after an applied save, shared request state and ownership, DEC-045, ACL entry and save guards, the three expiry cases of §7).
- AC-5 direct-call differential on the real store: plain strings, including `e1.`-shaped, exact `e1.`+64 hex, `e1.`+uppercase hex and malformed ones, through every method that writes each of the **ten** families (`score`, `block`, `budget`, `cycle`, `pause`, `reentry-claim`, `watch`, `watch-meta`, `distinct`, `distinct-meta`) give the same physical keys, values and results with E1 selected as with it unselected, and never reach the E1 prefix.
- AC-6 per-family differential on the real store: the same call sequence (score, block, budget, watch, cycle and pause with a previous key, lifecycle mutation, punishment lifecycle block, post-punishment claim, watch rotation) runs once on the store with v1 keys and once on its E1 view with E1 keys; all eight E1 families are written, every result is identical, and the stored values and minute-rounded expiries are identical per family. This is the routing evidence the in-memory laboratory cannot give (its stores return themselves as the E1 view).
- AC-7 ACL (ii) per layout: with `hset` granted by a Redis selector on one layout only, the request reaches the commit and receives `NOPERM`, and both layouts are unchanged, once with writes refused in E1 only and once in v1 only.
- AC-6 lifecycle and rotation through the real composition (runtime built by the builder over `RedisFullCapabilityStore`, one secret and after a rotation): three failures hard-block, the client returns after the block and receives re-entry evidence, `claimPostPunishmentReentry()` claims once and is refused the second time; under V2 no key exists under the E1 prefix, under E1 the families `budget`, `cycle`, `reentry-claim`, `score`, `watch`, `watch-meta` are under the E1 prefix and no claim marker is under v1; both encodings give the same outcomes. Routing the claim to the V2 store fails this test.
- AC-6 budget rotation through the real composition (runtime over `RedisFullCapabilityStore`, known-device failures so both the K4 and the micro-cap budgets are in play): after a secret rotation and after a device-generation (fingerprint) rotation the rotated runtime seeds a Current budget key from the Previous one (count plus the request's one, the Previous epoch start kept), the Previous budget key is read and never written after a secret rotation, every account-scope budget key is under the selected prefix and none in the other layout, and the saved budget state is identical in both encodings. Routing the seed to the V2 store fails this test.
- F295-02 (cancelled build): a build suspended inside a Host getter in a Fiber that is then destroyed releases the selection like a failed build (the cleanup is in `finally`, which a Fiber unwind runs and `catch (\Throwable)` does not); the encoding can be changed and the build then succeeds, for `build()` and for `buildOperationalReader()`.
- F295-02 (switch during a build): a Host callback that selects the other encoding from inside a running `build()` is refused; the runtime keeps its encoding (K4 only under v1 for V2) and its reader agrees; if the only attempt fails, the selection is free again.
- F295-02 (failed build): after a DEC-041 provider mismatch fails `build()` or `buildOperationalReader()`, either encoding can still be selected; the first successful build fixes it, and a failing outer build does not undo what a nested successful build fixed.
- F295-01/F295-02: a copy of a store with an existing view gets its own view and a cancelled request leaves neither layout written, on the original and on the copy; the encoding cannot change after the first build, from either order of runtime and reader, and the reader sees what the runtime wrote.
- AC-8: the existing suites for V2 unchanged.

**Known limits.** The DEC-003 cycle, DEC-007 lifecycle and budget-rotation suites that build their own pipelines or Redis stores are not re-run against E1 one by one; the laboratory suites above cover the evaluation logic under E1 and the per-family differential above covers the Redis routing of every family. Redis Cluster, replicas and non-official stores are not covered.

## 13. G1 acceptance boundary

The accepted G1 design and correction are on #284 comments 7156192985, 7156743430 and 7156769415. Required current evidence includes: sole-E1 public writer/direct construction/reader, unchanged vectors and Set B/C rules, closed helper injection and explicit clock roles, actual-view capability and ownership checks, stable handle handoff, failed/nested/cancelled builds, snapshot adoption, Current/Previous budget/lifecycle rotation, plain-string root compatibility in all ten families, both-layout atomicity/ACL/expiry/lost replies on PHP32/64, unsupported V2 transition, and comparative performance. Existing applicable suites, archive detectors and external Composer consumer verification remain required. Current results belong to the implementation PR's exact HEAD; historical #295 PASS is not G1 PASS.
