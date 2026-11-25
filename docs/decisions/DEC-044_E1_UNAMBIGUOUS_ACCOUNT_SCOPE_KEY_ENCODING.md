# DEC-044 — E1: An Unambiguous, Opt-In Encoding for Account-Scope Keys

## Decision ID

`DEC-044`

## Status

`SUPERSEDED` by [DEC-046](DEC-046_E1_ONLY_ACCOUNT_SCOPE_KEY_ENCODING.md). Historical acceptance: the Lead approved D-1 through D-6 under the Owner's delegation on [#284](https://github.com/Maatify/php-rate-limiter/issues/284) and accepted
the implementation and security review required by `KEY_STRATEGY.md` §10 in [PR #295](https://github.com/Maatify/php-rate-limiter/pull/295) at
[`ccfb86964159d126d57ca34615ee28f81333ef70`](https://github.com/Maatify/php-rate-limiter/commit/ccfb86964159d126d57ca34615ee28f81333ef70).
The proposed contract was recorded in PR #292. V2 remains the default. D-7 is a separate migration decision.

## Date

2026-11-22

## Decision Authority / Deciders

Proposed by the Executor on [#284](https://github.com/Maatify/php-rate-limiter/issues/284) at the Lead's assignment. The Lead approved the opt-in key format, public
interface and storage boundary under the Owner's delegation. The security review required by `KEY_STRATEGY.md` §10
is recorded in [PR #295](https://github.com/Maatify/php-rate-limiter/pull/295).

## Scope / Concern

How the package encodes the account-bearing fields of the K4/K4P, K5/K5p, micro-cap, budget-cooldown and auxiliary key
preimages before the HMAC, which HMAC key domain those keys use, and which physical storage identity they get, for runtimes
that **opt in**. Not in scope: any V2 behaviour, reading V2 from E1, seeding, the window policy, migration, K1–K3, the
admission layer.

## Context

- The V2 preimages join `policy`, `env` and `accountId` with `:` and no length prefix, in two independent copies (the
  writer and the operational reader). `environmentScope` and `accountId` accept `:`, so distinct `(env, accountId)` pairs
  can share a preimage, an HMAC and a stored key. Merged study #290 proved this from source and measured the ordinary
  writer/reader behaviour; the #291 rehearsal measured one operational cut-over option.
- The Owner's resume point on #284 asks for an independent E1 contract: explicit selection, existing configurations stay
  on V2 after a package update, a new isolated space may start on E1, moving the same existing traffic is a migration with
  its own contract, and reading V2 blocks, seeding and the window policy are outside E1.
- The store prefix `maatify:rate-limiter:v1` is shared by every family, so storage cannot be separated by changing the
  prefix constant without moving out-of-scope keys.
- KEY_STRATEGY §4.7 already locks `pack('N', strlen)` length-prefixed encoding for simple fixed-window keys, which is the
  in-repository precedent for a collision-safe preimage.

## Decision

1. **Encoding.** E1 hashes `MAGIC ‖ u32be(N) ‖ enc(purpose) ‖ enc(policy) ‖ enc(env) ‖ enc(account) [‖ enc(material)]`, with
   `enc(f) = u32be(strlen f) ‖ f`. Fields are opaque byte strings; no field is optional (an absent input yields no key, or a
   different purpose token). A field longer than `0xFFFFFFFF` bytes is refused before `pack()`, never truncated.
2. **Key domain.** `digest = HMAC-SHA256(HMAC-SHA256(secret, "maatify.rate-limiter.key-domain.e1"), preimage)`.
3. **Logical key and storage identity.** The textual form of the key is `"e1." ‖ digest`, but the layout is **carried
   explicitly** from the derivation to the store by the E1 layout-bound view (`E1KeyspaceStoreInterface::e1Keyspace()`,
   D-4(a)). In the eight families that hold in-scope keys an explicit E1 key is stored under `maatify:rate-limiter:e1:…`.
   The view and its root share one held request state, one ownership claim and one `commit` per attempt. A conflicting
   attempt saves nothing in either layout and DEC-037 re-evaluation applies. The successful attempt saves both layouts
   together. A plain string given to an existing store method, including one that starts with `e1.`, keeps its V2
   physical key byte for byte. An E1 key in a foreign family is refused before any command.
4. **One source.** One derivation component per encoding serves the writer, the operational reader and every future
   reader, for Current and Previous, created once by the builder. The V2 component returns byte-identical keys to today's.
5. **Scope.** Set A (re-derived): K4/K4P, K5/K5p, micro-cap, budget cooldown, the `aux` purposes. Set B (derivative, not
   re-derived): the distributed-account correlation keys that hash a K4/K5 key. Everything else is outside E1.
6. **Compatibility.** E1 is explicit and chosen once per builder. V2 remains the default across package updates. An E1 runtime
   reads and writes Set A state exclusively under E1. Other families follow the layout rules in the key map. A new isolated space
   may start on E1. Moving existing traffic is a migration under a separate contract that must preserve active blocks.
7. **Rotation, expiry, atomicity.** Current is writable and a single Previous generation is read-only. Budget seeding
   from Previous to Current is atomic and follows the existing prohibition on `max()` merges. The same physical
   lifetimes and scripts apply to both layouts. The request save applies DEC-037/041/042/045 to both layouts, including
   DEC-045's guard on the physical expiry of every key read by a writing request.

The full derivation contract, the key map by family, the vectors and the acceptance evidence are in
[`docs/KEY_SCOPE_ENCODING_E1.md`](../KEY_SCOPE_ENCODING_E1.md).

## Rationale

- Length-prefixed bytes make the preimage injective (within the `u32` bound) whatever the fields contain, without restricting `env`/`accountId`
  (which the package does not control) and without breaking a deployment that already uses `:`.
- The key domain, the encoding and the storage identity solve three different problems and are kept separate.
- Carrying the layout explicitly leaves every out-of-scope physical key and every existing direct call untouched, which a
  prefix change or routing by string shape could not. Key separation after the HMAC rests on HMAC-SHA256, not on the
  encoding.
- A shared derivation source removes the writer/reader drift risk that READER-PARITY only detects.

## Consequences

- Opt-in hosts get unambiguous account identities; V2 hosts see no change.
- A Host with a key-pattern ACL must grant the `e1` prefix, for reads and writes, before selecting E1. With only the `v1` grant the request is refused at the read or script entry, before any write (the entry guard, not the DEC-042 preflight). The DEC-042 `NOPERM` from `commit`, raised before the first write, is what an account that can read E1 keys but lacks a write permission of the plan receives. For direct scripts only DEC-043 applies: a refused expiry
  command is `NOPERM`, with its recorded cleanup bounds; E1 claims nothing wider.
- An E1 runtime starts every Set A identity empty; E1 does not carry V2 scores, budgets, cooldowns or blocks.
- Opting in requires `E1KeyspaceStoreInterface`. [PR #295](https://github.com/Maatify/php-rate-limiter/pull/295) records the implementation, the amendments to
  `KEY_STRATEGY.md` and the Package Reference, and the accepted security review.
- Adjacent findings recorded, not decided here: the admission layer joins fields with NUL (same ambiguity class), and
  KEY_STRATEGY §4.6 and the source disagree on the `binding_epoch` preimage.

## Alternatives that matter

- **Forbid `:` in `env`/`accountId`.** Closes one side, breaks existing deployments, and the package does not control the
  account identifiers a Host passes. Rejected in #290.
- **A NUL separator or base64 fields.** NUL is itself an accepted input; base64 only hides the boundary. Rejected in #290.
- **A new prefix constant for the whole store.** Moves K1–K3, correlation, simple throttling and admission keys. Rejected.
- **Decimal-length fields (`7:prod-eu`).** The #290 sketch; replaced by the `pack('N')` primitive of §4.7 to keep one length
  convention in the repository.

## Security review (KEY_STRATEGY §10)

The Executor reviewed the implementation in [PR #295](https://github.com/Maatify/php-rate-limiter/pull/295). The Lead accepted the security review on
[`ccfb86964159d126d57ca34615ee28f81333ef70`](https://github.com/Maatify/php-rate-limiter/commit/ccfb86964159d126d57ca34615ee28f81333ef70).

| Concern | Finding |
|---|---|
| Injectivity | The preimage is `MAGIC ‖ u32be(N) ‖ (u32be(len) ‖ field)*`; no content moves a boundary. A decoder recovers the exact tuple for a corpus with `:`, NUL, multibyte, empty and long fields, and the V2 ambiguity (`prod:eu`/`x` against `prod`/`eu:x`) collides under V2 and not under E1. A field longer than 2^32 − 1 bytes is refused before packing, HMAC or any command. |
| Key-domain separation | The HMAC key is `HMAC(secret, "maatify.rate-limiter.key-domain.e1")`; E1 digests are separated from V2 digests even for an equal text. This rests on HMAC-SHA256 under the secret, not on construction. |
| Layout carriage | The layout is carried by a typed view of the same store; a plain string given to any store method keeps its `v1` physical key, including `e1.`-shaped ones (tested in all ten families listed in the AC-5 direct-call differential). The view refuses anything not matching `^e1\.[0-9a-f]{64}$` and every family outside the eight that hold E1 keys, before any command. |
| Closed carrier creation | A view is obtained only from `e1Keyspace()`; the pipeline derives E1 keys only through the derivation component and re-validates the grammar of a stored snapshot member before blocking it in the E1 layout. |
| Single transaction | The view shares the root's request state, ownership and `transactRequest()`: one commit per attempt, both layouts saved together or none; a lost reply after an applied commit is an unknown outcome with a whole save (tested). |
| Secrets and data | No new place receives the secret, an account identifier or a physical key; the refusal messages name no key. |
| Residual risk | An account restricted to `v1` keys is refused at the read entry once E1 is selected (documented). E1 reads its own state for Set A identities. Redis Cluster and replicas are not covered. |

## Relationship to Other Decisions

This decision applies alongside the following decisions:

- **DEC-007, DEC-003:** lifecycle, claim and cycle semantics are unchanged; E1 changes only which key they run on.
- **DEC-016:** time authority unchanged.
- **DEC-026:** `k4p`, `k5p`, the `_p` purposes and the K5 material keep their meaning; they are distinct purpose tokens.
- **DEC-037, DEC-041, DEC-042, DEC-043:** the request save, exclusive ownership, ACL preflight and direct-script expiry
  classification apply to E1 keys.
- **[DEC-045](DEC-045_REQUEST_SAVE_REFUSES_AN_EXPIRY_LUA_CANNOT_READ_EXACTLY.md):** resolved [#287](https://github.com/Maatify/php-rate-limiter/issues/287) in the implementation base `main@4c523096`.
  Both layouts use the same guard for physical-expiry precision. A path that preserves the physical expiry is checked
  by literal before/after comparison. The logical deadline (caller time plus TTL, DEC-016) is compared separately.
  Fresh Redis-clock TTLs are measured against the `TIME` bracket of each run.
- **DEC-006 (superseded by DEC-016; its unaffected boundaries remain in force):** physical keys stay package-prefixed,
  namespace-isolated, deterministic and opaque.
- **#291 rehearsal:** evidence for one operational cut-over option, outside this decision.

## Canonical Contract / Current Owner

[`docs/KEY_STRATEGY.md`](../KEY_STRATEGY.md) is the normative owner. [`docs/KEY_SCOPE_ENCODING_E1.md`](../KEY_SCOPE_ENCODING_E1.md)
specifies the E1 framing, key map and acceptance evidence. The derivation component, `RedisFullCapabilityStore` and
`RateLimiterBuilder` implement the contract. The [Package Reference](../../RATE_LIMITER_PACKAGE_REFERENCE.md) describes
its public surface.

## Supersedes

None.

## Superseded By

DEC-046.
