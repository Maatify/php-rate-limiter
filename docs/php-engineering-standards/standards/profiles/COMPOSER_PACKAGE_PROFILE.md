# Composer Package Profile

## Profile Metadata

- **Profile ID:** `composer-package`
- **Profile Version:** `2.0.0`
- **Purpose / Applicability:** مكتبات PHP/Composer المستقلة القابلة لإعادة الاستخدام والتوزيع ضمن منظومة Maatify.
- **Extends:** `None`

## Required Standards

هذه هي الـ Standards المباشرة لهذا Profile:

- [Package Building Standard](../packages/PACKAGE_BUILDING_STANDARD.md)
- [Composer Package Standard](../packages/COMPOSER_PACKAGE_STANDARD.md)
- [CI Workflow Standard](../packages/CI_WORKFLOW_STANDARD.md)
- [Library Presentation Standard](../packages/LIBRARY_PRESENTATION_STANDARD.md)
- [Testing Standard](../testing/TESTING_STANDARD.md)
- [Documentation Lifecycle Standard](../governance/DOCUMENTATION_LIFECYCLE_STANDARD_AR.md)

لا ينقل هذا Profile محتوى أي Standard إلى ملف Profile.

## Conditional Applicability

تظل قواعد Persistence وDatabase وPDO وSchema والمigrations والاختبارات التابعة لها مشروطة بامتلاك الحزمة Database/Persistence behavior، كما يملكها [PACKAGE_BUILDING_STANDARD.md](../packages/PACKAGE_BUILDING_STANDARD.md). الحزمة التي لا تمتلك هذا السلوك لا تُلزم بهذه القواعد المشروطة.

## Resolved Dependency Behavior

لا يرث هذا Profile Profile آخر. تنتج Required Standards الستة `Candidate Standard References` عند تفعيله، ثم تُقيّم كل Candidate وفق canonical applicability لتكوين `Final Resolved Applicable Standards Set`. تضاف Additional Standards المصرح بها في Manifest إلى المرشحين وتخضع للمرحلتين نفسيهما.

عند تفعيل هذا Profile، يجب تثبيت ملفه محليًا ضمن `Pinned Adoption Control Set`؛ وتثبت كذلك ملفات Profiles الموروثة اللازمة للحل، بينما لا تُنسخ Profiles غير المفعلة.

## Scope Notes

يجب تفعيل Profile على Scope يمثل مكتبة Composer أو مسارها الفعلي. لا يجعل تفعيله Standards الموديولات أو الحوكمة منطبقة تلقائيًا.

## Precedence Notes

هذا الملف composition manifest. تفاصيل Package وComposer وCI وPresentation وTesting تظل مملوكة للملفات الأصلية، وآلية التفعيل والتثبيت مملوكة لـ [STANDARDS_ADOPTION_STANDARD_AR.md](../STANDARDS_ADOPTION_STANDARD_AR.md).
