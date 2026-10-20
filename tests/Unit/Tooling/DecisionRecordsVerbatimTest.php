<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\Unit\Tooling;

use Maatify\RateLimiter\Tests\Support\Admission\Archive\GateReport;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Stage 5-c, the records gate (5c/PLAN.md §4.2 row 7, §7.6.6; Lead, #150): a mechanical, byte-for-byte check of the text
 * the new records carry.
 *
 * - Every section DEC-026 carries from DEC-021, and every section DEC-027 carries from DEC-019, occurs in the new record
 *   byte for byte. DEC-026 carries Decision 3 whole: Delta 1 narrows it by a statement of its own and edits no carried
 *   byte, so the exception the plan allowed for that clause is not used.
 * - DEC-021 and DEC-019 differ from `main@13870bb` only in their `Status` and `Superseded By` sections: the sha-256 of each
 *   record without those two sections equals the value computed from `13870bb` (the CI checkout has no history to read).
 * - The Appendix A block of the contract is unchanged since `13870bb` (sha-256), and each of its paragraphs occurs in DEC-026.
 *
 * Detector: one byte changed in a carried section is not found.
 */
#[Group('records')]
final class DecisionRecordsVerbatimTest extends TestCase
{
    private const DEC021 = 'docs/decisions/DEC-021_KNOWN_DEVICE_VERIFIER_BOUND.md';
    private const DEC026 = 'docs/decisions/DEC-026_KNOWN_DEVICE_VERIFIER_BOUND_FOR_THE_ADMISSION_LAYER.md';
    private const DEC019 = 'docs/decisions/DEC-019_INTERIM_SECURITY_FIRST_CORRELATION_POSTURE.md';
    private const DEC027 = 'docs/decisions/DEC-027_RETAINED_CORRELATION_POSTURE_FOR_POLICIES_THAT_DO_NOT_OPT_INTO_ADMISSION.md';
    private const CONTRACT = 'investigations/k2-identity-admission-97/RUNTIME_IMPLEMENTATION_CONTRACT.md';

    /** sha-256 of the record without `Status` and `Superseded By`, and of the Appendix A block, computed at `main@13870bb`. */
    private const BASE = [
        self::DEC021 => '3daedc0526a8488ee3d8721de0969c4b91915599b9387d4b896c0f61f90c6b12',
        self::DEC019 => 'e72d61e5123513485a63fd7300adeeb90179ce9c8407b130f1197cc664d03907',
        'appendix-a' => '5642bd4dfa6df3d6d5d6fec6821a913e083c68a0d9a4d41404e6ce5bb2607cfd',
    ];

    private const DEC021_CARRIED = ['Decision Authority / Deciders', 'Context', 'Decision', 'Rationale', 'Consequences', 'Alternatives Not Adopted'];
    private const DEC019_CARRIED = ['Decision Authority', 'Scope / Concern', 'Context', 'Decision', 'Rationale', 'Consequences', 'Relationship to Other Decisions'];

    /** The leading words of every Appendix A paragraph DEC-026 reproduces (RIC lines 362 … 381 at `13870bb`). */
    private const APPENDIX_PARAGRAPHS = [
        '**Decision Authority.**', '**Scope / Concern.**', '**Carried unchanged from DEC-021', '1. **Pre-check statements narrowed.**',
        '2. **The allowance is tied to the P family', '3. **The known device on the strangers', '4. **K5 material for opted policies.**',
        '**Consequences added to DEC-021', '**Alternatives Not Adopted (added).**', '**Relationship to Other Decisions.**',
        '**Canonical Contract / Current Owner.**',
    ];

    private static function read(string $path): string
    {
        $text = file_get_contents(dirname(__DIR__, 3) . '/' . $path);
        self::assertIsString($text, $path);

        return $text;
    }

    /** The body of a `## heading` section, without the heading line and the surrounding blank lines. */
    private static function section(string $text, string $heading): string
    {
        $lines = explode("\n", $text);
        $start = array_search('## ' . $heading, $lines, true);
        self::assertIsInt($start, "section {$heading}");
        $body = [];
        for ($i = $start + 1, $n = count($lines); $i < $n && ! str_starts_with($lines[$i], '## '); $i++) {
            $body[] = $lines[$i];
        }

        return trim(implode("\n", $body), "\n");
    }

    private static function withoutStatusAndSupersededBy(string $text): string
    {
        return (string) preg_replace('/^## (Status|Superseded By)\n.*?(?=^## |\z)/ms', '', $text);
    }

    private static function appendixA(): string
    {
        $text = self::read(self::CONTRACT);
        $start = strpos($text, '### DEC-026 — ');
        $end = strpos($text, "\n---\n\n## Appendix B");
        self::assertIsInt($start);
        self::assertIsInt($end);

        return substr($text, $start, $end - $start);
    }

    public function testDec021AndDec019DifferFromTheBaseOnlyInStatusAndSupersededBy(): void
    {
        foreach ([self::DEC021, self::DEC019] as $record) {
            self::assertSame(self::BASE[$record], hash('sha256', self::withoutStatusAndSupersededBy(self::read($record))), $record);
        }
    }

    public function testEverySectionCarriedFromDec021IsInDec026ByteForByte(): void
    {
        self::assertCarried(self::DEC021, self::DEC026, self::DEC021_CARRIED, 'records-verbatim-dec026');
    }

    public function testEverySectionCarriedFromDec019IsInDec027ByteForByte(): void
    {
        self::assertCarried(self::DEC019, self::DEC027, self::DEC019_CARRIED, 'records-verbatim-dec027');
    }

    public function testAppendixAIsUnchangedAndEachOfItsParagraphsIsInDec026(): void
    {
        $appendix = self::appendixA();
        self::assertSame(self::BASE['appendix-a'], hash('sha256', $appendix));
        $record = self::read(self::DEC026);
        $found = 0;
        foreach (explode("\n", $appendix) as $line) {
            foreach (self::APPENDIX_PARAGRAPHS as $prefix) {
                if (str_starts_with($line, $prefix)) {
                    self::assertStringContainsString($line, $record, $prefix);
                    $found++;
                }
            }
        }
        self::assertSame(count(self::APPENDIX_PARAGRAPHS), $found, 'every registered paragraph exists in Appendix A once');
        GateReport::emit('records-verbatim-appendix-a', ['paragraphs' => $found, 'sha256' => self::BASE['appendix-a']]);
    }

    public function testDetectorOneChangedByteInACarriedSectionIsNotFound(): void
    {
        $context = self::section(self::read(self::DEC021), 'Context');
        $changed = substr_replace($context, $context[10] === 'x' ? 'y' : 'x', 10, 1);

        self::assertStringContainsString($context, self::read(self::DEC026));
        self::assertStringNotContainsString($changed, self::read(self::DEC026), 'DETECTOR: a one-byte change is visible');
    }

    /** @param list<string> $headings */
    private static function assertCarried(string $source, string $target, array $headings, string $report): void
    {
        $from = self::read($source);
        $to = self::read($target);
        $sizes = [];
        foreach ($headings as $heading) {
            $body = self::section($from, $heading);
            self::assertNotSame('', $body, $heading);
            self::assertStringContainsString($body, $to, "{$heading} of {$source} is not carried byte for byte");
            $sizes[$heading] = strlen($body);
        }
        GateReport::emit($report, ['source' => basename($source), 'target' => basename($target), 'bytesBySection' => $sizes]);
    }
}
