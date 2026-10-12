# سجل اعتماد معايير Maatify — `php-rate-limiter`

هذا الملف هو الـ Local Resolver Record المحلي وفق `STANDARDS_ADOPTION_STANDARD_AR.md` §9. يمثل Adoption مكتملًا فقط، وهو inventory وresolver record وليس Standard أو Profile.

## Upstream Repository

`https://github.com/Maatify/php-engineering-standards.git`

## Adoption Commit

`ebcf55b0591db61d18a1407bc3effef0197b388f`

## Adoption Metadata

- **Adoption Date:** `2026-10-12`
- **Adoption Mechanism:** Selective Pinned Upgrade (Stage 1 — Structural / Transitive Resolution، ثم Stage 2 — Canonical Standard Applicability)
- **Overall Resolution Status:** `VALID`
- **Artifact Facts:** Repository لمكتبة PHP مستقلة؛ Composer identity المستهدفة `maatify/php-rate-limiter`؛ Standalone reusable Composer package؛ Scope للحوكمة هو repository root `/`.

## Pinned Adoption Control Set

الملفات التالية منسوخة من exact upstream Adoption Commit المعلن أعلاه دون تعديل محتواها:

```text
docs/php-engineering-standards/standards/STANDARDS_ADOPTION_STANDARD_AR.md
docs/php-engineering-standards/standards/profiles/COMPOSER_PACKAGE_PROFILE.md
docs/php-engineering-standards/standards/profiles/REPOSITORY_GOVERNANCE_PROFILE.md
```

لا يتضمن Control Set أي Profile غير مفعّلة أو غير موروثة؛ كلا الـ Profiles المفعّلين `Extends: None`.

## Pinned Operational Assets Set

| Kind | Upstream Path | Local Path | SHA-256 |
|---|---|---|---|
| `skill` | `.agents/skills/maatify-standards-context-lifecycle/SKILL.md` | `.agents/skills/maatify-standards-context-lifecycle/SKILL.md` | `c2dc2e4274cda3d8618ad0d72867c2e21a5fc5e4199c88e476876c53ecb56735` |
| `skill` | `.agents/skills/maatify-strict-release-acceptance/SKILL.md` | `.agents/skills/maatify-strict-release-acceptance/SKILL.md` | `0f84c8265bc8d261fa4a93c695bd6d813f9903b90ec7b0d2a9cf65446f6e23ed` |
| `deterministic-helper` | `.agents/helpers/maatify-governance-context.php` | `.agents/helpers/maatify-governance-context.php` | `96ed5625416e8f58f24b5e9398adb95500ae78010d58130f17ddc17bdd0630b1` |
| `provider-adapter` | `.claude/skills/maatify-standards-context-lifecycle/SKILL.md` | `.claude/skills/maatify-standards-context-lifecycle/SKILL.md` | `96109fc0eee30348f46b565a6b64a4a51759bebabb4e4ed21e35171329f65133` |
| `provider-adapter` | `.claude/skills/maatify-strict-release-acceptance/SKILL.md` | `.claude/skills/maatify-strict-release-acceptance/SKILL.md` | `1b5af34a42d5adbca1aa52e2a25cc9beaa3631ebf8b8a21cd091f8c74756fb24` |

## Active Profiles

### Activation 1: `composer-package`

- **Profile Version:** `4.0.0`
- **Scope:** `/`
- **Resolution Status:** `VALID`
- **Exception State:** `NONE`

### Activation 2: `repository-governance`

- **Profile Version:** `3.0.0`
- **Scope:** `/`
- **Resolution Status:** `VALID`
- **Exception State:** `NONE`

## Final Resolved Applicable Standards Set

المجموعة النهائية الناتجة من تطبيق canonical applicability (Stage 2) على Candidate Standard References (Stage 1) لكل Activation/Scope:

```text
docs/php-engineering-standards/standards/packages/PACKAGE_BUILDING_STANDARD.md                  version 3.0.2
docs/php-engineering-standards/standards/packages/COMPOSER_PACKAGE_STANDARD.md                 version 5.0.0
docs/php-engineering-standards/standards/packages/CI_WORKFLOW_STANDARD.md                      version 5.0.0
docs/php-engineering-standards/standards/packages/LIBRARY_PRESENTATION_STANDARD.md             version 5.0.0
docs/php-engineering-standards/standards/testing/TESTING_STANDARD.md                           version 2.0.0
docs/php-engineering-standards/standards/governance/DOCUMENTATION_LIFECYCLE_STANDARD_AR.md    version 3.1.0
docs/php-engineering-standards/standards/php/PHP_SOURCE_DOCUMENTATION_STANDARD.md              version 1.0.0
docs/php-engineering-standards/standards/php/PHP_CODING_STYLE_STANDARD.md                     version 1.0.1
docs/php-engineering-standards/standards/ai/AI_COLLABORATION_WORKFLOW_AR.md                    version 12.0.0
docs/php-engineering-standards/standards/GITHUB_PHASE_STACK_WORKFLOW_AR.md                     version 4.0.0
docs/php-engineering-standards/standards/governance/DECISION_GOVERNANCE_STANDARD_AR.md         version 1.0.0
```

- Standards الناتجة عن `composer-package`: `PACKAGE_BUILDING_STANDARD.md` (3.0.2)، `COMPOSER_PACKAGE_STANDARD.md` (5.0.0)، `CI_WORKFLOW_STANDARD.md` (5.0.0)، `LIBRARY_PRESENTATION_STANDARD.md` (5.0.0)، `TESTING_STANDARD.md` (2.0.0)، `DOCUMENTATION_LIFECYCLE_STANDARD_AR.md` (3.1.0)، `PHP_SOURCE_DOCUMENTATION_STANDARD.md` (1.0.0)، `PHP_CODING_STYLE_STANDARD.md` (1.0.1).
- Standards الناتجة عن `repository-governance`: `AI_COLLABORATION_WORKFLOW_AR.md` (12.0.0)، `GITHUB_PHASE_STACK_WORKFLOW_AR.md` (4.0.0)، `DOCUMENTATION_LIFECYCLE_STANDARD_AR.md` (3.1.0)، `DECISION_GOVERNANCE_STANDARD_AR.md` (1.0.0).

لا تُسجَّل `STANDARD_VERSIONING_POLICY_AR.md` ضمن المجموعة النهائية؛ فهي Central Governance Policy وليست Applicable Engineering Standard لمجرد وجودها في upstream.

لا تُسجَّل `EXTERNAL_PROVIDER_VERIFICATION_STANDARD.md` (std-external-provider-verification 1.0.0) ضمن المجموعة النهائية؛ فقد استُبعدت canonical في Stage 2 لعدم امتلاك Scope `/` أي عقد لمزود خارجي خاضع للتحكم الخارجي (externally controlled provider contract)، واعتبار Redis خدمة تكامل خاضعة لإدارة المستودع (repository-controlled / provisionable integration service).

لا تُسجَّل أي Candidate Standard مستبعدة canonical ضمن هذه المجموعة النهائية.

## Explicit Additional Standards

```text
None
```

## Explicit Exceptions/Overrides

```text
None
```
