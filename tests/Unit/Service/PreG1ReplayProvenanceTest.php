<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\Unit\Service;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/** The V2 historical controls retain the exact executable bodies from the approved G1 base. */
#[Group('php32')]
final class PreG1ReplayProvenanceTest extends TestCase
{
    public function testHistoricalBodiesHaveNotBeenChangedToMakeOldMixedVersionDetectorsPass(): void
    {
        $expected = [
            'EvaluationPipeline' => '5261c2fb37af8c55a9e2b4f0616b6699227bf96139534f0d7a43eb27857a0090',
            'AccountScopeKeyDeriverInterface' => '8fc5fe139a18473d442204a472d3afc071381b037e65ed249d27127f3a38a2d9',
            'V2AccountScopeKeyDeriver' => '9a411115b067044a36bd1580f9069cd4404e681e86cfb8bb6412e2e4d577798b',
            'KeyScopeEncodingEnum' => '4cbff232d6a2736c58e0acada108cfdeb55d9985f52ee5056d34f0a90b18eb85',
        ];
        foreach ($expected as $class => $digest) {
            $source = file_get_contents(dirname(__DIR__, 2) . '/Fixtures/PreG1/' . $class . '.php');
            self::assertIsString($source);
            $active = false;
            $body = '';
            foreach (token_get_all($source) as $token) {
                if (is_array($token)) {
                    if (in_array($token[0], [T_CLASS, T_INTERFACE, T_ENUM], true)) {
                        $active = true;
                    }
                    if (! $active || in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                        continue;
                    }
                    $body .= $token[1];
                } elseif ($active) {
                    $body .= $token;
                }
            }
            self::assertSame($digest, hash('sha256', $body), $class . ': source main@6db137868f4abaebb94a287f75a3af1742d8d3bc; namespace/import relocation only.');
        }
    }
}
