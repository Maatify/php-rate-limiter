# Shared NAT architecture proposals — under #110

**Type:** design record and comparison only — no `src/`, no `tests/`, no Public API, no Store Contract, no DEC. Nothing here is an approved runtime candidate.
**Umbrella:** [#122](https://github.com/Maatify/php-rate-limiter/issues/122).

## Purpose

This folder is the single source for the proposal texts. Each design is a separate file, copied verbatim from the source the Owner supplied and numbered in order of arrival. The proposal files keep their original language (Arabic) because they are verbatim records. A file is created only when it has content.

## Index

| # | Source | Status | File | Lines | Characters | UTF-8 bytes | SHA-256 |
|---|---|---|---|---|---|---|---|
| 1 | KIMI 3 — "Anchored Fair Limiter" (AFL) | UNREVIEWED / VERBATIM | [proposals/01-kimi-3.md](proposals/01-kimi-3.md) | 289 | 20,420 | 33,098 | `e08f1342243b7bbaa1af6efc0fc71e9aba0c3333ee4cbcb2c660e63f9a10220d` |
| 2 | Gemini Argon — "Sovereign Quota & Asymmetric Escalation Engine" (SQAEE) | UNREVIEWED / VERBATIM | [proposals/02-gemini-argon.md](proposals/02-gemini-argon.md) | 487 | 39,806 | 60,720 | `361a773618fe10e427376cdace96bae788417c6d6049586fda360e9289dd6944` |
| 3 | Gemini 3.1 Pro — "Contextual Risk Enclaves & Challenge-Response Routing" | UNREVIEWED / VERBATIM | [proposals/03-gemini-3-1-pro.md](proposals/03-gemini-3-1-pro.md) | 213 | 9,811 | 15,298 | `77a52e28c4b4208500bd8afbd3f1cc5686519fc1f9f1883ff4f935ff3c9809f6` |
| 4 | DeepSeek — "Hierarchical Fair-Share Admission Control with Client Instance Accounting and Graduated Challenges" | UNREVIEWED / VERBATIM | [proposals/04-deepseek.md](proposals/04-deepseek.md) | 371 | 16,535 | 25,108 | `55c90cb72405f6b8d6bb04f9fa841aa6a0895359bd90b035f914c486a73f87fb` |
| 5 | Grok — two lanes, non-multiplicable spray budget, narrowest punishment | UNREVIEWED / VERBATIM | [proposals/05-grok.md](proposals/05-grok.md) | 246 | 24,054 | 40,449 | `1b5fb2157b418a95072c8a2e5f2253f1b83a9a718674a71f3b7cc24911829f61` |
| 6 | GPT-6.1 Sol — bounded admission with target-bound proof and reserved shares | UNREVIEWED / VERBATIM | [proposals/06-gpt-6-1-sol.md](proposals/06-gpt-6-1-sol.md) | 922 | 35,974 | 60,647 | `08b5680b457fc088b21a98cfdac5a28b490ef8993896f2e55d936185f0a5c96d` |
| 7 | GPT-6 Astra — proof-based admission for account and operation (lanes U/P) | UNREVIEWED / VERBATIM | [proposals/07-gpt-6-astra.md](proposals/07-gpt-6-astra.md) | 621 | 33,669 | 57,167 | `6699f88ba1c5546426a938ca480b998d77c407ae2284a4a6428ff289d32a8b02` |
| 8 | GPT-6 Luna — atomic admission before the verifier with escalation to extra proof | UNREVIEWED / VERBATIM | [proposals/08-gpt-6-luna.md](proposals/08-gpt-6-luna.md) | 264 | 20,895 | 35,321 | `d442a23e05ff3090a6bcec6f27989aaacb7e7f67614f67ac3b7c3df6f9b06bfb` |
| 9 | GPT-6.5 Terra — "Hierarchical Costed Admission Control" | UNREVIEWED / VERBATIM | [proposals/09-gpt-6-5-terra.md](proposals/09-gpt-6-5-terra.md) | 220 | 10,328 | 15,921 | `9d626820e7fa476269e81073006e98f363963e4fb7989aba9b22f8c0b6f94db8` |
| 10 | Fable 5.1 — minted identity and pair-level admission | UNREVIEWED / VERBATIM | [proposals/10-fable-5-1.md](proposals/10-fable-5-1.md) | 404 | 36,600 | 59,075 | `67cd22828e0dae192e1222337d7d37323a015695d63bb489b153af15a0996bfb` |
| 11 | Opus 5.5 — "Pairwise Admission with Novelty Budgets" | UNREVIEWED / VERBATIM | [proposals/11-opus-5-5.md](proposals/11-opus-5-5.md) | 398 | 25,890 | 41,774 | `f69479abd38a6c597096e13d9cd9d84e029cab338a58f3486987769f05c0f605` |

- Every text was supplied by the Owner on 2026-10-16 and recorded verbatim, including errors present in the original. Hashes are computed on the files in this repository.
- Source reconciliation (commit `54e119e`): 01, 02, 05, 10 and 11 are byte-identical to the SHA-256 recorded for their originals (05 after restoring the original order of 21 Arabic diacritic pairs). 03, 04, 06, 07, 08 and 09 have no complete original outside the Owner's chat paste; 04 matches the only recorded part. Details in [`COMPARISON.md` §0.5](COMPARISON.md).
- Design 10 is a byte-for-byte copy of the Owner's file `NAT_ARCHITECTURE_STUDY_2026-10-16_AR.md`, including the Owner decision lines recorded inside it.
- The record is complete at eleven designs (Owner decision, 2026-10-16). No further designs are expected.

## Comparison

[`COMPARISON.md`](COMPARISON.md) — paper comparison of the eleven designs, pinned to a source commit. It is a draft for engineering review: no ranking, no winner, no candidate selection.
