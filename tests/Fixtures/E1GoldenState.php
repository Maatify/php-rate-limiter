<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\Fixtures;

/**
 * Current storage snapshots under sole-E1 account derivation (DEC-046) and canonical non-account framing (DEC-047),
 * separate from the retained historical V2 / pre-G2 goldens.
 *
 * Traceability from pre-G2 G1 baseline (`main@8cf34c55`) to G2 (`DEC-047`):
 * - Every historical S0 decision stream (`AdmissionBuilderS0GoldensTest::GOLDEN`, `FamilyS0ParityTest::GOLDEN`)
 *   is byte-for-byte identical.
 * - Direct structural comparison of `main@8cf34c55` vs G2 across all 10 fixtures proves:
 *   (a) all E1 account keys (`e1.*`) and their stored values/expiries are 100% identical (302/302 entries);
 *   (b) all non-account root store buckets (`data`, `blocks`, `hardBlockCycles`, `budgets`, `claimMarkers`,
 *       `flags`, `sets`) retain identical counts, identical stored value/expiry multisets (940/940 entries),
 *       and identical `e1.*` K5 snapshot members; only the non-account 64-hex logical key digests changed
 *       from legacy colon-joined preimages to `DEC-047` `f1` framing:
 *   - BUILDER[login_protection][A]:     be62e0ce93dd -> 944ea0e26a24 (38 E1 exact, 79 non-E1 values identical)
 *   - BUILDER[login_protection][B]:     263ce6736c88 -> 0c10352dfc5d (44 E1 exact, 99 non-E1 values identical)
 *   - BUILDER[otp_protection][A]:       815e274197d7 -> 9a8bc053b093 (36 E1 exact, 73 non-E1 values identical)
 *   - BUILDER[otp_protection][B]:       5b9ec660147d -> 5f77d2f6cb98 (44 E1 exact, 90 non-E1 values identical)
 *   - BUILDER[api_heavy_protection][A]: c3875563250e -> 25b3677e3e78 (0 E1 exact, 117 non-E1 values identical)
 *   - BUILDER[api_heavy_protection][B]: 6855c39e1d6d -> e305b9efc1b0 (0 E1 exact, 190 non-E1 values identical)
 *   - BUILDER[custom_auth][A]:          2a8b1836fdf5 -> 8a4c1eb9080d (34 E1 exact, 67 non-E1 values identical)
 *   - BUILDER[custom_auth][B]:          ecbc2a7c77da -> 9b6fbde8293f (32 E1 exact, 113 non-E1 values identical)
 *   - PIPELINE[login_protection]:       bd54f5adaa3e -> 3d8f33520fec (38 E1 exact, 79 non-E1 values identical)
 *   - PIPELINE[otp_protection]:         74c79feed900 -> 06991e517290 (36 E1 exact, 73 non-E1 values identical)
 *
 * These hashes remain strict state detectors, and the existing mutants must still fail against this baseline.
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
