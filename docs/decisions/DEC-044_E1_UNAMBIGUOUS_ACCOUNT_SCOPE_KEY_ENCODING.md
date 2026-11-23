# DEC-044 — E1: An Unambiguous, Opt-In Encoding for Account-Scope Keys

## Decision ID

`DEC-044`

## Status

`PROPOSED` — not approved. It is not implementation authority and does not change the current key contract
(`docs/KEY_STRATEGY.md`), the default derivation, a stored key or any code. Activation is the Owner's decision (D-1 in the
contract document).

## Date

2026-11-22

## Decision Authority / Deciders

Proposed by the Executor on [#284](https://github.com/Maatify/php-rate-limiter/issues/284) at the Lead's assignment. The
Owner decides: the change is a key-format change (`KEY_STRATEGY.md` §10 requires an explicit version and a security
review), it adds public interface surface, and it is a security and data boundary.

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
   explicitly** from the derivation to the store (a layout-bound handle or a typed key), never inferred from the string.
   In the eight families that hold in-scope keys an explicit E1 key is stored under `maatify:rate-limiter:e1:…`. The two layouts are served by **one store instance** (a layout-bound view of it, or a typed key): one held request state, one ownership claim and one `commit`, so a request that writes both layouts is saved in both or refused in both. A mechanism that creates a second store or overlay is excluded. Every
   plain string given to an existing store method, including one that starts with `e1.`, keeps its V2 physical key byte for
   byte and is never refused; an E1 key in a foreign family is refused before any command.
4. **One source.** One derivation component per encoding serves the writer, the operational reader and every future
   reader, for Current and Previous, created once by the builder. The V2 component returns byte-identical keys to today's.
5. **Scope.** Set A (re-derived): K4/K4P, K5/K5p, micro-cap, budget cooldown, the `aux` purposes. Set B (derivative, not
   re-derived): the distributed-account correlation keys that hash a K4/K5 key. Everything else is outside E1.
6. **Compatibility.** E1 is explicit and chosen once per builder; V2 remains the default across package updates; E1 never
   reads or writes V2 state; a new isolated space may start on E1; moving existing traffic is a migration under a separate
   contract that must preserve active blocks.
7. **Rotation, expiry, atomicity.** Unchanged. Current is writable, Previous read-only, one Previous generation, no `max()`
   merge, budget seeding atomic; physical lifetimes, scripts and the request save (DEC-037/041/042) are as they are. E1
   acceptance on the request path is blocked on #287.

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
- Requires a new E1 store capability (public interface addition) and, on activation, amendments to `KEY_STRATEGY.md`
  and the Package Reference with the security review §10 of KEY_STRATEGY requires.
- Adjacent findings recorded, not decided here: the admission layer joins fields with NUL (same ambiguity class), and
  KEY_STRATEGY §4.6 and the source disagree on the `binding_epoch` preimage.

## Alternatives that matter

- **Forbid `:` in `env`/`accountId`.** Closes one side, breaks existing deployments, and the package does not control the
  account identifiers a Host passes. Rejected in #290.
- **A NUL separator or base64 fields.** NUL is itself an accepted input; base64 only hides the boundary. Rejected in #290.
- **A new prefix constant for the whole store.** Moves K1–K3, correlation, simple throttling and admission keys. Rejected.
- **Decimal-length fields (`7:prod-eu`).** The #290 sketch; replaced by the `pack('N')` primitive of §4.7 to keep one length
  convention in the repository.

## Relationship to Other Decisions

No `ACTIVE` decision closes the encoding choice. Read and bound by this proposal:

- **DEC-007, DEC-003:** lifecycle, claim and cycle semantics are unchanged; E1 changes only which key they run on.
- **DEC-016:** time authority unchanged.
- **DEC-026:** `k4p`, `k5p`, the `_p` purposes and the K5 material keep their meaning; they are distinct purpose tokens.
- **DEC-037, DEC-041, DEC-042, DEC-043:** the request save, exclusive ownership and permission preflight apply to E1 keys
  unchanged. `#287` (preserve an inherited expiry on commit) gates E1 acceptance on the request path, judged by a literal
  before/after comparison of the **physical** expiry on a path that pins it. The logical deadline (caller time plus TTL, DEC-016) is compared separately, and new Redis-clock TTLs are bracketed by `TIME`, never compared across runs.
- **DEC-006 (superseded by DEC-016; its unaffected boundaries remain in force):** physical keys stay package-prefixed,
  namespace-isolated, deterministic and opaque.
- **#291 rehearsal:** evidence for one operational cut-over option, outside this decision.

## Canonical Contract / Current Owner

While `PROPOSED`: [`docs/KEY_SCOPE_ENCODING_E1.md`](../KEY_SCOPE_ENCODING_E1.md) is the reviewable proposal and
`docs/KEY_STRATEGY.md` remains the current contract. On activation the normative owner becomes `KEY_STRATEGY.md`
(amended), with the derivation component, `RedisFullCapabilityStore`, `RateLimiterBuilder` and
`RATE_LIMITER_PACKAGE_REFERENCE.md`.

## Supersedes

None.

## Superseded By

None.
