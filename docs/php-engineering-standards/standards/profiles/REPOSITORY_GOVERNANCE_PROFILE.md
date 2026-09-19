# Repository Governance Profile

## Profile Metadata

- **Profile ID:** `repository-governance`
- **Profile Version:** `2.0.0`
- **Purpose / Applicability:** إدارة التعاون، دورة Phase، والحوكمة التشغيلية في Repository تتبع Maatify engineering workflow.
- **Extends:** `None`

## Required Standards

هذه هي الـ Standards المباشرة لهذا Profile:

- [AI Collaboration Workflow](../ai/AI_COLLABORATION_WORKFLOW_AR.md)
- [GitHub Phase Stack Workflow](../GITHUB_PHASE_STACK_WORKFLOW_AR.md)
- [Documentation Lifecycle Standard](../governance/DOCUMENTATION_LIFECYCLE_STANDARD_AR.md)

لا يضم هذا Profile Package أو Module standards.

## Conditional Applicability

ينتج تفعيل هذا Profile مراجع `Required Standards` كـ`Candidate Standard References` ضمن Scope المحدد. بعد اكتمال Stage 1، تُقيّم كل Candidate وفق canonical applicability المملوكة للـStandard لتكوين `Final Resolved Applicable Standards Set`؛ لا يجعل تفعيل Profile أي Standard منطبقة مباشرةً ولا يوسع أو يضيق applicability الخاصة بها. تفاصيل الأدوار والـ Phase lifecycle تظل مملوكة للملفين المشار إليهما.

## Resolved Dependency Behavior

لا يرث هذا Profile Profile آخر. تنتج Required Standards الثلاث `Candidate Standard References` عند تفعيله، ثم تُقيّم كل Candidate وفق canonical applicability لتكوين `Final Resolved Applicable Standards Set`. تضاف Additional Standards المصرح بها صراحةً في Manifest المشروع إلى المرشحين وتخضع للمرحلتين نفسيهما.

عند تفعيل هذا Profile، يجب تثبيت ملفه محليًا ضمن `Pinned Adoption Control Set`؛ وتثبت كذلك ملفات Profiles الموروثة اللازمة للحل، بينما لا تُنسخ Profiles غير المفعلة.

## Scope Notes

يجب أن يسجل المشروع Scope صريحًا لكل Activation، مثل `/` أو مسارات الحوكمة التي يشملها العقد. يمكن تفعيله إلى جانب Profiles أخرى في Repository نفسها.

## Precedence Notes

هذا الملف composition manifest فقط. تطبق قواعد adoption من [STANDARDS_ADOPTION_STANDARD_AR.md](../STANDARDS_ADOPTION_STANDARD_AR.md)، وتظل قواعد التعاون ودورة Phase مملوكة للـ Standards المطلوبة.
