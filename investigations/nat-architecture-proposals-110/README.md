# Shared NAT architecture proposals — under #110

**Type:** design record and comparison only — no `src/`, no `tests/`, no Public API, no Store Contract, no DEC. Nothing here is an approved runtime candidate.
**Umbrella:** [#122](https://github.com/Maatify/php-rate-limiter/issues/122).

## Purpose

This folder is the single source for the proposal texts. Each design is a separate file, copied verbatim from the source the Owner supplied and numbered in order of arrival. The proposal files keep their original language (Arabic) because they are verbatim records. A file is created only when it has content.

## Index

| # | Source | Status | File | Lines | Characters | UTF-8 bytes | SHA-256 |
|---|---|---|---|---|---|---|---|
| 1 | KIMI 3 — "Anchored Fair Limiter" (AFL) | UNREVIEWED / VERBATIM | [proposals/01-kimi-3.md](proposals/01-kimi-3.md) | 289 | 20,421 | 33,099 | `f67c02590751a32a2d997c6c4263bf134c55eebecf6998fb7e613acf63f43b4a` |
| 2 | Gemini Argon — "Sovereign Quota & Asymmetric Escalation Engine" (SQAEE) | UNREVIEWED / VERBATIM | [proposals/02-gemini-argon.md](proposals/02-gemini-argon.md) | 487 | 39,807 | 60,721 | `49c732cc8553759196060d423816be71a10c5713c5e2bfeb2422c9e0e26c46aa` |
| 3 | Gemini 3.1 Pro — "Contextual Risk Enclaves & Challenge-Response Routing" | UNREVIEWED / VERBATIM | [proposals/03-gemini-3-1-pro.md](proposals/03-gemini-3-1-pro.md) | 213 | 9,812 | 15,299 | `ece025d4067db873280aa2fc4eb721e2d6975997b5859b3cd9f6065ba1dde3e6` |
| 4 | DeepSeek — "Hierarchical Fair-Share Admission Control with Client Instance Accounting and Graduated Challenges" | UNREVIEWED / VERBATIM | [proposals/04-deepseek.md](proposals/04-deepseek.md) | 371 | 16,536 | 25,109 | `a5983432edb8cfdc370743749d7df9645f6a1087e0c18ca4d9e109f21006bcf2` |
| 5 | Grok — two lanes, non-multiplicable spray budget, narrowest punishment | UNREVIEWED / VERBATIM | [proposals/05-grok.md](proposals/05-grok.md) | 246 | 24,055 | 40,450 | `0c8337ff6a24fa64c7add0bbb77885b894637764f69e4c4d36fdbb33ad732c50` |
| 6 | GPT-6.1 Sol — bounded admission with target-bound proof and reserved shares | UNREVIEWED / VERBATIM | [proposals/06-gpt-6-1-sol.md](proposals/06-gpt-6-1-sol.md) | 922 | 35,975 | 60,648 | `51093b4c5333cb7ef32439c0f7dec3044e5756fe2862a7b41b780e9ecf4f4729` |
| 7 | GPT-6 Astra — proof-based admission for account and operation (lanes U/P) | UNREVIEWED / VERBATIM | [proposals/07-gpt-6-astra.md](proposals/07-gpt-6-astra.md) | 621 | 33,670 | 57,168 | `6add4bee5011366cae39fc64a2d96733b3357f19339b3a1804be41d06a8ee6b9` |
| 8 | GPT-6 Luna — atomic admission before the verifier with escalation to extra proof | UNREVIEWED / VERBATIM | [proposals/08-gpt-6-luna.md](proposals/08-gpt-6-luna.md) | 264 | 20,896 | 35,322 | `fe1b9dd63491e9b030c4f0ab497c654ed354413c19b678e3e00cdadad5fbbbe8` |
| 9 | GPT-6.5 Terra — "Hierarchical Costed Admission Control" | UNREVIEWED / VERBATIM | [proposals/09-gpt-6-5-terra.md](proposals/09-gpt-6-5-terra.md) | 220 | 10,329 | 15,922 | `2650412df72b90da32db9e2e885c93ac231aaaf2c281124da3c7fa6707baf2e3` |
| 10 | Fable 5.1 — minted identity and pair-level admission | UNREVIEWED / VERBATIM | [proposals/10-fable-5-1.md](proposals/10-fable-5-1.md) | 404 | 36,600 | 59,075 | `67cd22828e0dae192e1222337d7d37323a015695d63bb489b153af15a0996bfb` |
| 11 | Opus 5.5 — "Pairwise Admission with Novelty Budgets" | UNREVIEWED / VERBATIM | [proposals/11-opus-5-5.md](proposals/11-opus-5-5.md) | 398 | 25,891 | 41,775 | `b511388d710f2b919f6f173cfe4462afb187dd430289a3d330667a48e4a311cd` |

- Every text was supplied by the Owner on 2026-10-16 and recorded verbatim, including errors present in the original. Hashes are computed on the files in this repository.
- Design 10 is a byte-for-byte copy of the Owner's file `NAT_ARCHITECTURE_STUDY_2026-10-16_AR.md`, including the Owner decision lines recorded inside it.
- The record is complete at eleven designs (Owner decision, 2026-10-16). No further designs are expected.

## Comparison

[`COMPARISON.md`](COMPARISON.md) — paper comparison of the eleven designs, pinned to a source commit. It is a draft for engineering review: no ranking, no winner, no candidate selection.
