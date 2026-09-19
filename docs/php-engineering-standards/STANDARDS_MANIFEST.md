# سجل اعتماد معايير Maatify — `php-rate-limiter`

هذا الملف هو الـ Local Resolver Record المحلي وفق `STANDARDS_ADOPTION_STANDARD_AR.md` §9. يمثل Adoption مكتملًا فقط، وهو inventory وresolver record وليس Standard أو Profile.

## Upstream Repository

`Maatify/php-engineering-standards`

## Adoption Commit

`4e268089d0aceedbc837d98f28da8b204d39dd7f`

## Adoption Metadata

- **Adoption Date:** `2026-09-19`
- **Adoption Mechanism:** Selective Pinned Adoption (Stage 1 — Structural / Transitive Resolution، ثم Stage 2 — Canonical Standard Applicability)
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

## Active Profiles

### Activation 1: `composer-package`

- **Profile Version:** `2.0.0`
- **Scope:** `/`
- **Resolution Status:** `VALID`
- **Exception State:** `NONE`

### Activation 2: `repository-governance`

- **Profile Version:** `2.0.0`
- **Scope:** `/`
- **Resolution Status:** `VALID`
- **Exception State:** `NONE`

## Final Resolved Applicable Standards Set

المجموعة النهائية الناتجة من تطبيق canonical applicability (Stage 2) على Candidate Standard References (Stage 1) لكل Activation/Scope:

```text
docs/php-engineering-standards/standards/packages/PACKAGE_BUILDING_STANDARD.md                  version 2.0.0
docs/php-engineering-standards/standards/packages/COMPOSER_PACKAGE_STANDARD.md                 version 3.0.0
docs/php-engineering-standards/standards/packages/CI_WORKFLOW_STANDARD.md                      version 2.0.0
docs/php-engineering-standards/standards/packages/LIBRARY_PRESENTATION_STANDARD.md             version 2.0.0
docs/php-engineering-standards/standards/testing/TESTING_STANDARD.md                           version 1.1.0
docs/php-engineering-standards/standards/ai/AI_COLLABORATION_WORKFLOW_AR.md                    version 7.0.0
docs/php-engineering-standards/standards/GITHUB_PHASE_STACK_WORKFLOW_AR.md                     version 3.0.0
docs/php-engineering-standards/standards/governance/DOCUMENTATION_LIFECYCLE_STANDARD_AR.md    version 1.0.0
```

- Standards الناتجة عن `composer-package`: `PACKAGE_BUILDING_STANDARD.md` (2.0.0)، `COMPOSER_PACKAGE_STANDARD.md` (3.0.0)، `CI_WORKFLOW_STANDARD.md` (2.0.0)، `LIBRARY_PRESENTATION_STANDARD.md` (2.0.0)، `TESTING_STANDARD.md` (1.1.0)، `DOCUMENTATION_LIFECYCLE_STANDARD_AR.md` (1.0.0).
- Standards الناتجة عن `repository-governance`: `AI_COLLABORATION_WORKFLOW_AR.md` (7.0.0)، `GITHUB_PHASE_STACK_WORKFLOW_AR.md` (3.0.0)، `DOCUMENTATION_LIFECYCLE_STANDARD_AR.md` (1.0.0).

لا تُسجَّل `STANDARD_VERSIONING_POLICY_AR.md` ضمن المجموعة النهائية؛ فهي Central Governance Policy وليست Applicable Engineering Standard لمجرد وجودها في upstream.

لا تُسجَّل أي Candidate Standard مستبعدة canonical ضمن هذه المجموعة النهائية.

## Explicit Additional Standards

```text
None
```

## Explicit Exceptions/Overrides

```text
None
```
