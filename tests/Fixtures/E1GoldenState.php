<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\Fixtures;

/**
 * Current storage snapshots under sole-E1 account derivation (DEC-046) and canonical non-account framing (DEC-047),
 * separate from the retained historical V2 / pre-G2 goldens.
 * Recorded only after exact byte-for-byte equality of every historical decision stream was verified. Root/view
 * serialization and logical key bytes intentionally differ; these hashes remain strict state detectors, and the
 * existing mutants must still fail against this baseline.
 */
final class E1GoldenState
{
    public const BUILDER =  [
        'login_protection'
         => [
             'A' => '944ea0e26a2493ca79ff6225b4aa019d6b2e2a67dd1742811e729893c86cc116',
             'B' => '0c10352dfc5db27b3e7a9e3dbeb51e2d29090a77bc78ce5bfced25f5cfcdd2b7',
         ],
        'otp_protection'
         => [
             'A' => '9a8bc053b0931164693512904ef70296327ac4437d1dc176b651638244fbc501',
             'B' => '5f77d2f6cb988e83592b62cd6cba87e1a467a61dc51ef785164d325b39ab8049',
         ],
        'api_heavy_protection'
         => [
             'A' => '25b3677e3e784bb176a115fa5bdfdeaa0589834f6ecd685399dabe73dfb7abdf',
             'B' => 'e305b9efc1b0aaf98f0f0c0211a3f4a0a9df19e5c68e2906931662eb86dbb58a',
         ],
        'custom_auth'
         => [
             'A' => '8a4c1eb9080dd3b10e75e104ab811749963b5b144e9de8a700ca7860d5e9b412',
             'B' => '9b6fbde8293f740b406473235ab49b7c0194030913dc8d0a215c462ab04d113c',
         ],
    ];

    public const PIPELINE =  [
        'login_protection' => '3d8f33520fecbfaa0af475f48f5ec0ac6d8020b1915115aa473cfb8b173d00eb',
        'otp_protection' => '06991e517290350b608384e6d4c7cdc26c93ae64c6644b95ffc9d011032e9afe',
    ];
}
