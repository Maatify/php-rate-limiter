<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\Fixtures;

/**
 * G1 E1 storage snapshots, separate from the retained V2 historical goldens.
 * Recorded only after exact equality of every historical decision stream was checked. Root/view serialization and logical key bytes
 * intentionally differ; these hashes remain strict state detectors, and the existing mutants must still fail against this baseline.
 */
final class E1GoldenState
{
    public const BUILDER =  [
        'login_protection'
         => [
             'A' => 'be62e0ce93dd08c356a476892a2fe251eabcd0506b2e913eee2757354d1f7f8b',
             'B' => '263ce6736c8837bd252844bf28a6fa209ab1a7fc2b61ec09c96ea00127576458',
         ],
        'otp_protection'
         => [
             'A' => '815e274197d7dca2263d018a48b8f287edb8108e0bc7729e7c48d7562f69be80',
             'B' => '5b9ec660147d1aefc8b06e7b5bb9dcfd53c08a19d6549593bd47ec330131c2c8',
         ],
        'api_heavy_protection'
         => [
             'A' => 'c3875563250e62aca413cf48c43f9f1834d04b2a341193a028c79b46bbe8310a',
             'B' => '6855c39e1d6d06b75ef1008e066b8b228b8e80a22b08185734618d5e6dbfa656',
         ],
        'custom_auth'
         => [
             'A' => '2a8b1836fdf5515e9175501be18ebf06891b327eb8d098a640a9555bf2ec42c8',
             'B' => 'ecbc2a7c77da92a24a0661508fde33e5163fb9164865e946b9088b36ea0cfa82',
         ],
    ];

    public const PIPELINE =  [
        'login_protection' => 'bd54f5adaa3eb8104a0e0665c46188e8b9b917302a9add0aeeabfea1933ed366',
        'otp_protection' => '74c79feed9002fa0c38e2858f11170acc5efbc3c7fd78a447d210cd4dcd0ab42',
    ];
}
