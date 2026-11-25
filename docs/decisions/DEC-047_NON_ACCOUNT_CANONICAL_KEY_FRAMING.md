# DEC-047 — Canonical Framing for Non-Account Keys (G2)

## Decision ID

`DEC-047`

## Status

`ACTIVE` — Pre-Stable breaking boundary approved by the Project Owner on 2026-11-25 in [#284](https://github.com/Maatify/php-rate-limiter/issues/284#issuecomment-7171487299) under the closure plan in [#281](https://github.com/Maatify/php-rate-limiter/issues/281#issuecomment-7157227625); bounded G2 design and execution authorized by the Technical Lead in [#284](https://github.com/Maatify/php-rate-limiter/issues/284#issuecomment-7171856985). Implementation remains subject to independent Lead review; this record is not implementation, merge, or release acceptance.

## Date

2026-11-25

## Decision Authority / Deciders

Project Owner (pre-Stable breaking-state authority without live migration); Technical Lead (bounded G2 framing design, purpose registry, retention boundaries, and execution assignment).

## Scope / Concern

Canonical length-prefixed framing for non-account rate-limiter key derivations (K1–K3, correlation, and adaptive IPv6 families), shared checked `u32be` framing bounds across E1, non-account `f1`, and simple fixed-window derivations, and consolidation of simple fixed-window writer/reader derivation.

This record does **not** supersede `DEC-046` or `DEC-027` in whole; it amends only the non-account key preimage encoding in `docs/KEY_STRATEGY.md` (§3.1, §4.2, §4.4, §10) and adds checked `u32be` length validation to simple fixed-window framing (§4.7) without altering E1 or simple fixed-window bytes. Admission keys (`A1`–`A3`), `binding_epoch`, fleet fence, token cohort, and trust-domain semantics remain queued for G3 under [#281](https://github.com/Maatify/php-rate-limiter/issues/281#issuecomment-7157227625) and [#284](https://github.com/Maatify/php-rate-limiter/issues/284#issuecomment-7156192985).

## Context

Adjacent finding `A4` ([#284 comment 7156192985](https://github.com/Maatify/php-rate-limiter/issues/284#issuecomment-7156192985)) established that non-account key preimages joined opaque `policyName` and `environmentScope` fields (and subsequent semantic fields) with literal `:` delimiters (e.g., `{policy}:rate_limiter:k1:v2:{env}:{ipPrefix}`). Because `BlockPolicyInterface::getName()` and `RateLimiterConfig::environmentScope()` accept opaque strings containing `:`, two distinct `(policy, env)` pairs sharing one store and key secret produce byte-identical preimages and colliding HMAC digests across K1–K3, correlation, and adaptive IPv6 families even for valid IPv4/IPv6 addresses and canonical User-Agents.

Separately, `FixedWindowSimpleRateLimiter::deriveKey()` and `SimpleRateLimitOperationalReader::deriveKey()` duplicated unchecked `pack('N', strlen($component))` calls, which silently wrap lengths exceeding `2^32 - 1` modulo `2^32` on 64-bit PHP.

## Decision

### 1. Shared Checked `u32be` Framing Primitive (`KeyPreimageFraming`)

1. One internal primitive (`Maatify\RateLimiter\Service\KeyPreimageFraming`) encodes unsigned 32-bit big-endian lengths and tuple counts (`pack('N', $value)`) using byte length (`strlen`), never character count.
2. Any field byte length or tuple count outside `0..4294967295` (`0..0xFFFFFFFF`) throws `RateLimiterException` **before** `pack()`, HMAC, or any store command. No silent modulo wrap on 64-bit PHP and no narrowing or float packing on 32-bit PHP occur.
3. No trimming, case folding, or extra normalization is performed on field bytes. Internal length/count seams exist for boundary testing without allocating multi-gigabyte strings and are never injectable into runtime constructors.
4. `E1AccountScopeKeyDeriver` reuses `KeyPreimageFraming::encodeTuple()` with unchanged `MAGIC`, `KEY_DOMAIN`, `e1.` output, purpose registry, and bytes (`DEC-046`).
5. `SimpleFixedWindowKeyDeriver` consolidates `FixedWindowSimpleRateLimiter` and `SimpleRateLimitOperationalReader` onto one immutable internal implementation using `KeyPreimageFraming::encodeFields()` with the exact existing 8-component order (`rate_limiter`, `simple_fixed_window`, `v1`, `environmentScope`, `policyName`, `(string) $limit`, `(string) $intervalSeconds`, `subject`), no extra magic/count prefix, and direct `hash_hmac('sha256', $preimage, $secret)` — byte-identical to pre-G2 simple keys.

### 2. Canonical Non-Account Key Derivation (`NonAccountScopeKeyDeriver`, `f1`)

1. Affected non-account families use the canonical wire format:
   ```text
   MAGIC    = "maatify.rate-limiter.non-account.f1" || 0x00          (36 bytes)
   preimage = MAGIC || u32be(N) || enc(f_1) || enc(f_2) || ... || enc(f_N)
   enc(f)   = u32be(strlen(f)) || f
   subkey   = HMAC-SHA256(key = secret, data = "maatify.rate-limiter.key-domain.non-account.f1", raw = true)
   key      = lowercase-hex(HMAC-SHA256(key = subkey, data = preimage))   (64 hex chars)
   ```
2. `f_1` is a closed registered `purpose` token, `f_2` is `policy`, `f_3` is `env`, followed by semantic fields in existing order (`cidr` as canonical decimal string where applicable).
3. Output keys carry **no** `e1.` prefix, require **no** regex routing, and remain in the existing physical root layout `maatify:rate-limiter:v1:{sha256(namespace)}:{family}:{sha256(logical)}`.
4. `EvaluationPipeline` and `RateLimitOperationalReader` instantiate the same immutable internal `NonAccountScopeKeyDeriver` for K1–K3 from their validated `envScope`; object identity is not a contract.
5. Compound anchors that previously concatenated values with `:` (`device_cap_account` `k4:k1` and `dilution_confirmation` `scope:windowId`) are split into separate framed fields.
6. IP/UA/fingerprint normalization, identity presence rules, policy thresholds, TTLs, caps, score/lifecycle semantics, and Current/Previous secret/material rotation (alias deduplication, Previous read-only, bridges, budget seeding) are unchanged.

### 3. Closed Purpose Registry (`NonAccountScopePurpose`)

Unregistered purposes and arity mismatches fail closed with `RateLimiterException` before framing or HMAC. No free-form Host purpose or generic public derivation API is exposed.

| # | Registered Purpose Token | `N` | Tuple (`f_1 .. f_N`) | Replaced Legacy Preimage |
|---|---|---|---|---|
| 1 | `k1` | 4 | `purpose`, `policy`, `env`, `ipPrefix` | `{policy}:rate_limiter:k1:v2:{env}:{ipPrefix}` |
| 2 | `k2` | 5 | `purpose`, `policy`, `env`, `ipPrefix`, `ua` | `{policy}:rate_limiter:k2:v2:{env}:{ipPrefix}:{ua}` |
| 3 | `k3` | 5 | `purpose`, `policy`, `env`, `ipPrefix`, `fpHash` | `{policy}:rate_limiter:k3:v2:{env}:{ipPrefix}:{fpHash}` |
| 4 | `correlation.device_cap_ip.scope` | 4 | `purpose`, `policy`, `env`, `k1` | `{policy}:rate_limiter:correlation:device_cap_ip:v1:{env}:scope:{k1}` |
| 5 | `correlation.device_cap_ip.member` | 4 | `purpose`, `policy`, `env`, `fpHash` | `{policy}:rate_limiter:correlation:device_cap_ip:v1:{env}:member:{fpHash}` |
| 6 | `correlation.device_cap_ip.bridge` | 4 | `purpose`, `policy`, `env`, `scopeKey` | `{policy}:rate_limiter:correlation:device_cap_ip:v1:{env}:bridge:{scopeKey}` |
| 7 | `correlation.device_cap_account.scope` | 5 | `purpose`, `policy`, `env`, `k4`, `k1` | `{policy}:rate_limiter:correlation:device_cap_account:v1:{env}:scope:{k4}:{k1}` |
| 8 | `correlation.device_cap_account.member` | 4 | `purpose`, `policy`, `env`, `fpHash` | `{policy}:rate_limiter:correlation:device_cap_account:v1:{env}:member:{fpHash}` |
| 9 | `correlation.device_cap_account.bridge` | 4 | `purpose`, `policy`, `env`, `scopeKey` | `{policy}:rate_limiter:correlation:device_cap_account:v1:{env}:bridge:{scopeKey}` |
| 10 | `correlation.churn.scope` | 4 | `purpose`, `policy`, `env`, `k2` | `{policy}:rate_limiter:correlation:churn:v1:{env}:scope:{k2}` |
| 11 | `correlation.churn.member` | 4 | `purpose`, `policy`, `env`, `fpHash` | `{policy}:rate_limiter:correlation:churn:v1:{env}:member:{fpHash}` |
| 12 | `correlation.churn.bridge` | 4 | `purpose`, `policy`, `env`, `scopeKey` | `{policy}:rate_limiter:correlation:churn:v1:{env}:bridge:{scopeKey}` |
| 13 | `correlation.churn_watch` | 4 | `purpose`, `policy`, `env`, `scopeKey` | `{policy}:rate_limiter:correlation:churn_watch:v1:{env}:{scopeKey}` |
| 14 | `correlation.dilution.scope` | 4 | `purpose`, `policy`, `env`, `fpHash` | `{policy}:rate_limiter:correlation:dilution:v1:{env}:scope:{fpHash}` |
| 15 | `correlation.dilution.member` | 4 | `purpose`, `policy`, `env`, `k1` | `{policy}:rate_limiter:correlation:dilution:v1:{env}:member:{k1}` |
| 16 | `correlation.dilution.bridge` | 4 | `purpose`, `policy`, `env`, `scopeKey` | `{policy}:rate_limiter:correlation:dilution:v1:{env}:bridge:{scopeKey}` |
| 17 | `correlation.dilution_watch` | 4 | `purpose`, `policy`, `env`, `scopeKey` | `{policy}:rate_limiter:correlation:dilution_watch:v1:{env}:{scopeKey}` |
| 18 | `correlation.dilution_confirmation` | 5 | `purpose`, `policy`, `env`, `scopeKey`, `windowId` | `{policy}:rate_limiter:correlation:dilution_confirmation:v1:{env}:{scopeKey}:{windowId}` |
| 19 | `correlation.distributed_account_devices.scope` | 4 | `purpose`, `policy`, `env`, `k4` | `{policy}:rate_limiter:correlation:distributed_account_devices:v1:{env}:scope:{k4}` |
| 20 | `correlation.distributed_account_devices.bridge` | 4 | `purpose`, `policy`, `env`, `scopeKey` | `{policy}:rate_limiter:correlation:distributed_account_devices:v1:{env}:bridge:{scopeKey}` |
| 21 | `correlation.distributed_account_watch` | 4 | `purpose`, `policy`, `env`, `scopeKey` | `{policy}:rate_limiter:correlation:distributed_account_watch:v1:{env}:{scopeKey}` |
| 22 | `correlation.distributed_account_occurrences.scope` | 4 | `purpose`, `policy`, `env`, `k4` | `{policy}:rate_limiter:correlation:distributed_account_occurrences:v1:{env}:scope:{k4}` |
| 23 | `correlation.distributed_account_occurrences.window` | 4 | `purpose`, `policy`, `env`, `windowExpiresAt` | `{policy}:rate_limiter:correlation:distributed_account_occurrences:v1:{env}:window:{windowExpiresAt}` |
| 24 | `correlation.distributed_account_occurrences.bridge` | 4 | `purpose`, `policy`, `env`, `scopeKey` | `{policy}:rate_limiter:correlation:distributed_account_occurrences:v1:{env}:bridge:{scopeKey}` |
| 25 | `ipv6_adaptive.hierarchy.scope` | 5 | `purpose`, `policy`, `env`, `cidr`, `prefix` | `{policy}:rate_limiter:ipv6_adaptive:hierarchy:v1:{cidr}:{env}:{prefix}` |
| 26 | `ipv6_adaptive.hierarchy.bridge` | 5 | `purpose`, `policy`, `env`, `cidr`, `scopeKey` | `{policy}:rate_limiter:ipv6_adaptive:hierarchy:v1:{cidr}:{env}:bridge:{scopeKey}` |
| 27 | `ipv6_adaptive.churn_anchor` | 6 | `purpose`, `policy`, `env`, `cidr`, `adaptiveScope`, `ua` | `{policy}:rate_limiter:ipv6_adaptive:churn_anchor:v1:{cidr}:{env}:{adaptiveScope}:{ua}` |
| 28 | `ipv6_adaptive.churn.scope` | 5 | `purpose`, `policy`, `env`, `cidr`, `anchor` | `{policy}:rate_limiter:ipv6_adaptive:churn:v1:{cidr}:{env}:scope:{anchor}` |
| 29 | `ipv6_adaptive.churn.member` | 5 | `purpose`, `policy`, `env`, `cidr`, `fpHash` | `{policy}:rate_limiter:ipv6_adaptive:churn:v1:{cidr}:{env}:member:{fpHash}` |
| 30 | `ipv6_adaptive.churn.bridge` | 5 | `purpose`, `policy`, `env`, `cidr`, `scopeKey` | `{policy}:rate_limiter:ipv6_adaptive:churn:v1:{cidr}:{env}:bridge:{scopeKey}` |
| 31 | `ipv6_adaptive.spray.scope` | 5 | `purpose`, `policy`, `env`, `cidr`, `anchor` | `{policy}:rate_limiter:ipv6_adaptive:spray:v1:{cidr}:{env}:scope:{anchor}` |
| 32 | `ipv6_adaptive.spray.member` | 5 | `purpose`, `policy`, `env`, `cidr`, `subject` | `{policy}:rate_limiter:ipv6_adaptive:spray:v1:{cidr}:{env}:member:{subject}` |
| 33 | `ipv6_adaptive.spray.bridge` | 5 | `purpose`, `policy`, `env`, `cidr`, `scopeKey` | `{policy}:rate_limiter:ipv6_adaptive:spray:v1:{cidr}:{env}:bridge:{scopeKey}` |

### 4. Independent Synthetic Vectors

Fixtures: `S = "synthetic-key-secret-not-for-production"`, `SP = "synthetic-previous-secret-not-for-production"`.

| Case | Secret | Tuple (`purpose`, `policy`, `env`, semantic fields...) | Output Digest (`64` lowercase hex) |
|---|---|---|---|
| K1 | `S` | `k1`, `api_heavy_protection`, `prod-eu`, `203.0.113.10` | `22dd5d9bfbebd867e7cf973c5ed744b6299b046b8f1ca121ee88714304cc76f5` |
| K1 Previous | `SP` | `k1`, `api_heavy_protection`, `prod-eu`, `203.0.113.10` | `5b5764bc1f0475e467d11012ece0c6b86192b0acb9f089aa15ac7b80730a3304` |
| K2 | `S` | `k2`, `api_heavy_protection`, `prod-eu`, `203.0.113.10`, `chrome/126` | `94b81a4dfa457f8c99aa3298d62f56372cfa1c3f549aa4559cd942e8b8bcd79d` |
| K3 | `S` | `k3`, `api_heavy_protection`, `prod-eu`, `203.0.113.10`, `f00dcafe` | `46b53421ddb2d7b8987d7042c039bb15b78456026d0d9d516fe3d2125e9ef5e6` |
| K1 opaque bytes | `S` | `k1`, `a:rate_limiter:k1:v2:b`, `prod:eu\x00\xc3\xbc`, `203.0.113.10` | `16c4b4066cc992426dacdcbda91568f4d58aa576b530c13780686d6502c2bb26` |
| `device_cap_account` scope | `S` | `correlation.device_cap_account.scope`, `login_protection`, `prod-eu`, `e1.9678f6f2458e4fc59a0b91701343b4a1e07c81144d9e40025bb2e9aa2b825fa7`, `deadbeef` | `e6f16114d40d9f084c41e95d46643cbb1193e19ed73b5789bfeff087adbe8169` |
| `dilution_confirmation` | `S` | `correlation.dilution_confirmation`, `otp_protection`, `prod-eu`, `cafebabe`, `2989656` | `42abfdcb2d88c59df66139499f3ab4586d08f4ed9a2a68f427fb4c4d47c89239` |
| `distributed_account_occurrences` window | `S` | `correlation.distributed_account_occurrences.window`, `login_protection`, `prod-eu`, `1793794200` | `9777ab243adf10a6e03824ca829bfd2dd3c1add5dae40f1273166f6bd1941c55` |
| IPv6 hierarchy scope | `S` | `ipv6_adaptive.hierarchy.scope`, `login_protection`, `prod-eu`, `48`, `20010db80001` | `87397d63d25fff15bc4b77599588e2b19bc33776ef40119e7b19437d70804fed` |
| IPv6 churn anchor | `S` | `ipv6_adaptive.churn_anchor`, `login_protection`, `prod-eu`, `48`, `abcd1234`, `chrome/126` | `6c9a0595fe039e4b85150c235935f099e75ef6f9e437e4f84f343b6b08cc8ff7` |
| IPv6 spray member | `S` | `ipv6_adaptive.spray.member`, `login_protection`, `prod-eu`, `48`, `acct-0001` | `f838132799a1340663636ffe92d9d009b3de665d339022b5309afafe6835a611` |
| Simple fixed-window retention golden | `S` | `prod-eu`, `public_burst`, `10`, `60`, `subj-0001` (unchanged wire bytes) | `7986d5743dd9092f6afaf0b23e08e8ec2e971d2e6603ac99619062a55702ef81` |

Hand-checked K1 preimage hex: `6d6161746966792e726174652d6c696d697465722e6e6f6e2d6163636f756e742e663100` (`MAGIC`) + `00000004` (`N=4`) + `000000026b31` (`k1`) + `000000146170695f68656176795f70726f74656374696f6e` (`api_heavy_protection`) + `0000000770726f642d6575` (`prod-eu`) + `0000000c3230332e302e3131332e3130` (`203.0.113.10`).

### 5. Complete Source and Storage Classification (Change / Retain / Queued G3)

| Surface / Family | Stored Redis Families | Pre-G2 Classification | G2 Disposition | Basis / Evidence |
|---|---|---|---|---|
| K1, K2, K3 (`EvaluationPipeline`, `RateLimitOperationalReader`) | `v1`: `score`, `block`, `cycle`, `pause`, `watch`, `watch-meta` (`watch:{key}`) | **Reachable A4 witness** | **CHANGE** (`f1`) | Direct `(policy, env)` colon-boundary shift collides on valid IP/UA/fp; `watch:{k1|k2|k3}` inherits K1–K3 reset automatically (`dependency-only reset`). |
| Correlation `device_cap_ip`, `churn`, `churn_watch`, `dilution`, `dilution_watch`, `dilution_confirmation` | `v1`: `distinct`, `distinct-meta`, `watch`, `watch-meta` | **Reachable A4 witness** | **CHANGE** (`f1`) | Direct `(policy, env)` colon-boundary shift; `dilution_confirmation` split into separate `(scopeKey, windowId)` fields. |
| Correlation `device_cap_account` (`scope`, `member`, `bridge`) | `v1`: `distinct`, `distinct-meta` | **Structural ambiguity** (K4 anchor is E1-derived; outer join and `k4:k1` anchor were colon-joined) | **CHANGE** (`f1`) | Replaced outer join with `f1`; split `k4:k1` into two framed fields (`k4`, `k1`). |
| Distributed account `devices` (`scope`, `bridge`, `watch`) & `occurrences` (`scope`, `window`, `bridge`) | `v1`: `distinct`, `distinct-meta`, `watch`, `watch-meta` | **Structural ambiguity** (`occurrences.window` member also has direct preimage witness on `(policy, env, windowExpiresAt)`) | **CHANGE** (`f1`); **RETAIN** K5 snapshot member | K5 snapshot member stays the raw E1 enforcement key `e1.<64hex>` (blocks target `e1:block:`). |
| IPv6 adaptive `hierarchy` (`scope`, `bridge`), `churn_anchor`, `churn` (`scope`/`member`/`bridge`), `spray` (`scope`/`member`/`bridge`) | `v1`: `distinct`, `distinct-meta` | **Reachable A4 witness** | **CHANGE** (`f1`) | Direct `(policy, env)` colon-boundary shift on valid IPv6 `/48`/`/40`/`/32`; CIDR framed as canonical decimal. |
| Macro churn/spray watch suffixes (`$scopeKey . ':watch'`) & `credential_spray` (`credential_spray:{k1}`, `credential_spray:bridge:{k1}`, `credential_spray:subject:v1:{subject}`) | `v1`: `distinct`, `distinct-meta`, `watch`, `watch-meta` | **Safe grammar** + **dependency-only reset** (on K1 / macro scope change) | **RETAIN** wrapper & subject HMAC | Fixed prefix/suffix around fixed-grammar 64-hex key or single opaque `$subject` tail; no boundary ambiguity. K1 / macro scope changes isolate spray/watch state automatically. |
| E1 Account-Scope keys (`K4`/`K4P`, `K5`/`K5p`, micro-cap, cooldown, account `aux.*`) | `e1`: `score`, `block`, `budget`, `cycle`, `pause`, `reentry-claim`, `watch`, `watch-meta` | **Safe (DEC-046)** | **RETAIN** bytes; share `KeyPreimageFraming` | Unchanged bytes, vectors, `e1:` layout, and `DEC-046` contract; gains checked `u32be` count/negative guards. |
| Simple Fixed-Window (`FixedWindowSimpleRateLimiter`, `SimpleRateLimitOperationalReader`) | `v1`: `budget` | **Safe preimage**; missing `u32be` upper bound | **RETAIN** bytes; consolidate into `SimpleFixedWindowKeyDeriver` | Byte-identical output; shared writer/reader deriver with checked `0..2^32-1` bound before `pack('N')`. |
| `LocalFallbackLimiter` (`:109`, `:144`, `:163`) | In-process memory only (not Redis) | **Safe grammar** | **RETAIN** bytes, MD5, and DEC-011/015 semantics | `DeviceIdentityResolver::normalizeUserAgent()` produces only `(opera\|edge\|firefox\|chrome\|safari\|other)/[0-9]+` (colon-free); last `:` in `"{$normalizedIp}:{$normalizedUa}"` uniquely splits IP and UA even for colon-bearing invalid IPs. Policy-only namespace and 4096 bound preserved. |
| `DeviceIdentityResolver` (`:39`, `:46`) & `FingerprintHasher` | Upstream hash material (not stored directly as keys) | **Safe grammar** | **RETAIN** unchanged | `"v2\|"` vs `"id1\|"` are disjoint; `$ua` is pipe-free; `$clientFp` is either `""` (followed by `'|'`) or canonical non-empty JSON object/array (starts with `'{'`/`'['` and is strictly prefix-free by balanced JSON delimiters outside strings); `$sessionFp` / `$instanceId` are single opaque tails. |
| `CircuitBreaker` (`circuit`, `probe`) & Redis physical root layout | `v1`: `circuit`, `probe`; root `v1:` / `e1:` | **Safe grammar** | **RETAIN** unchanged | Single opaque `$policyName` per family; fixed physical layout segments. |
| Admission layer (`AdmissionRuntime`, `RedisAdmissionOperations`, `AdmissionCredentialCodec`, `binding_epoch`, fence, cohort) | `v1`: `{ns}:adm:*` | **Adjacent blockers A1–A3** | **QUEUED FOR G3** | Explicitly out of scope for G2; untouched here (shared score path inherits G2 non-account framing without changing Admission semantics). |

## Rationale

Length-prefixed framing with a closed purpose registry and dedicated HMAC key domain eliminates field-boundary ambiguity across all affected non-account families unconditionally within `0..2^32 - 1` (preimage injectivity; digest distinctness rests on HMAC-SHA256 collision resistance). Consolidating writer and operational reader derivation for both non-account K1–K3 and simple fixed-window throttling eliminates drift between writers and readers while preserving all unaffected storage layouts and byte contracts.

## Consequences

1. **Pre-Stable breaking storage boundary (affected families only).** Moving existing traffic to G2 creates a fresh isolated key space for K1–K3 (`score`, `block`, `cycle`, `pause`, `watch`, `watch-meta`), correlation (`distinct`, `distinct-meta`, `watch`, `watch-meta`), adaptive IPv6 state, and K1-dependent credential-spray scopes/bridges/watches. Existing traffic does not see prior state for those families until old TTLs expire.
2. **Unsupported operations.** Mixed pre-G2 / post-G2 workers and live rollback are unsupported. Active blocks in affected non-account families are not preserved across format boundaries. No Redis data is deleted; no migration, dual-read, dual-write, or compatibility layer is provided.
3. **Preserved state.** E1 account-bearing state (`e1:` layout) and simple fixed-window state (`v1:budget`) retain identical bytes and have no intended reset. Current/Previous secret and material rotation **inside `f1`** remains fully supported (Previous read-only, alias deduplication, bridges, caps, TTLs).
4. **Remaining blockers.** `A1`–`A3` (Admission NUL joins, `binding_epoch`, cohort/trust-domain semantics) remain open for G3 under [#281](https://github.com/Maatify/php-rate-limiter/issues/281#issuecomment-7157227625) and [#284](https://github.com/Maatify/php-rate-limiter/issues/284). This record does not claim rc.4 qualification or Stable readiness.

## Canonical Contract / Current Owner

`docs/KEY_STRATEGY.md`, `RATE_LIMITER_PACKAGE_REFERENCE.md`, `docs/guides/USAGE_GUIDE.md`, `KeyPreimageFraming`, `NonAccountScopePurpose`, `NonAccountScopeKeyDeriver`, `SimpleFixedWindowKeyDeriver`, `EvaluationPipeline`, `RateLimitOperationalReader`, `FixedWindowSimpleRateLimiter`, and `SimpleRateLimitOperationalReader`.

## Supersedes

None. (Amends non-account key preimage derivation in `docs/KEY_STRATEGY.md` and checked `u32be` framing in `E1AccountScopeKeyDeriver` and simple fixed-window derivation without superseding `DEC-046`, `DEC-027`, `DEC-012`, or `DEC-013`.)

## Superseded By

None.
