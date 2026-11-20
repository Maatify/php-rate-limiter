<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\System;

use Maatify\RateLimiter\Command\RateLimitCommand;
use Maatify\RateLimiter\DTO\FailureSignalDTO;
use Maatify\RateLimiter\DTO\PostPunishmentReentryMetadataDTO;
use Maatify\RateLimiter\DTO\RateLimitContextDTO;
use Maatify\RateLimiter\DTO\RateLimitMetadataDTO;
use Maatify\RateLimiter\DTO\RateLimitResultDTO;
use Maatify\RateLimiter\Tests\Support\Study262\FallbackStudyRuntime as R;
use Maatify\RateLimiter\Tests\Support\FailureSignal\RecordingFailureSignalEmitter;
use Maatify\RateLimiter\Tests\Support\Study263\AllowlistDiagnosticRecord;
use Maatify\RateLimiter\Tests\Support\Study263\EmitterRuntime;
use Maatify\RateLimiter\Tests\Support\Study263\LoggingFailureSignalEmitter;
use Maatify\RateLimiter\Tests\Support\Study263\RecordingLogSink;
use Maatify\RateLimiter\Tests\Support\Study263\SyntheticInputs as S;
use PHPUnit\Framework\TestCase;

/**
 * #263 study — a CHARACTERIZATION of the public JSON shapes of the request-context and result DTOs and of what a Host log sink receives
 * from each Host-side step. Synthetic values only, each marked SYN263-*; nothing here is a real credential, header or token.
 * Evidence lines are written when STUDY_263_EVIDENCE names a file.
 */
final class DtoSerializationLoggingStudy263Test extends TestCase
{
    private const POLICY = 'study_263_policy';

    /** @return list<string> the markers found in $text, in a stable order */
    private static function markersIn(string $text): array
    {
        $found = [];
        foreach (array_keys(S::MARKERS) as $marker) {
            if (str_contains($text, $marker)) {
                $found[] = $marker;
            }
        }

        return $found;
    }

    private static function json(mixed $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR);
    }

    /** @return array<mixed> */
    private static function decode(string $json): array
    {
        return self::arr(json_decode($json, true, 512, JSON_THROW_ON_ERROR));
    }

    /** @return array<mixed> */
    private static function arr(mixed $value): array
    {
        self::assertIsArray($value);

        return $value;
    }

    public function testContextMinimalShapeKeepsNullKeysAndOmitsAdmissionKeys(): void
    {
        $json = self::json(S::minimalContext());
        $d = self::decode($json);

        $this->assertSame(['ip', 'ua', 'accountId', 'clientFingerprint', 'sessionDeviceId', 'isSessionTrusted', 'headers', 'isDevicePreviouslyVerifiedForAccount', 'correlationId'], array_keys($d));
        foreach (['accountId', 'clientFingerprint', 'sessionDeviceId', 'correlationId'] as $key) {
            $this->assertArrayHasKey($key, $d);
            $this->assertNull($d[$key]); // present with null
        }
        $this->assertArrayNotHasKey('clientInstanceId', $d);
        $this->assertArrayNotHasKey('admissionCredential', $d);
        $this->record('S01_context_minimal', ['inputs' => 'ip, ua'], ['keys' => array_keys($d), 'nullValuedKeys' => ['accountId', 'clientFingerprint', 'sessionDeviceId', 'correlationId'], 'absentKeys' => ['clientInstanceId', 'admissionCredential']], ['json' => $json, 'keys' => array_keys($d)]);
    }

    public function testContextFullSerializesEveryMarkerIncludingNestedAndAdmission(): void
    {
        $json = self::json(S::fullContext());
        $d = self::decode($json);
        $found = self::markersIn($json);
        $contextMarkers = array_values(array_filter(array_keys(S::MARKERS), static fn(string $m): bool => !str_starts_with(S::MARKERS[$m], 'metadata.')));

        $this->assertSame($contextMarkers, $found);
        $this->assertArrayHasKey('clientInstanceId', $d);
        $this->assertArrayHasKey('admissionCredential', $d);
        $this->assertSame(['SYN263-COOKIE-A', 'SYN263-COOKIE-B'], self::arr(self::arr($d['headers'])['Cookie'] ?? null));
        $this->record('S02_context_full', ['inputs' => 'every input set, admission inputs included'], 'every context marker is present in the JSON', ['markersFound' => $found, 'markersExpected' => $contextMarkers, 'keys' => array_keys($d)]);
    }

    public function testOmittedAdmissionFieldEqualsNullButDiffersFromEmptyString(): void
    {
        $omitted = self::json(new RateLimitContextDTO(S::IP, 'SYN263-UA'));
        $explicitNull = self::json(new RateLimitContextDTO(S::IP, 'SYN263-UA', null, null, null, false, [], false, null, null, null));
        $empty = self::json(S::emptyAdmissionContext());
        $dEmpty = self::decode($empty);
        $withAccountNullVsAbsent = self::decode($omitted);

        $this->assertSame($omitted, $explicitNull); // null and omitted are the same input: the key is absent
        $this->assertArrayHasKey('clientInstanceId', $dEmpty);
        $this->assertSame('', $dEmpty['clientInstanceId']); // empty string is a value: the key is present
        $this->assertSame('', $dEmpty['admissionCredential']);
        $this->assertArrayHasKey('accountId', $withAccountNullVsAbsent); // a non-admission null keeps its key, with null
        $this->record('S03_absent_vs_null_vs_empty', ['cases' => ['omitted', 'explicit null', 'empty string']], ['omitted == explicit null (admission keys absent)', 'empty string => key present with ""', 'non-admission null => key present with null'], ['omittedEqualsNull' => $omitted === $explicitNull, 'omittedAdmissionKeys' => array_values(array_intersect(['clientInstanceId', 'admissionCredential'], array_keys(self::decode($omitted)))), 'emptyStringAdmissionKeys' => ['clientInstanceId' => $dEmpty['clientInstanceId'], 'admissionCredential' => $dEmpty['admissionCredential']], 'accountIdNullKeyPresent' => array_key_exists('accountId', $withAccountNullVsAbsent)]);
    }

    public function testResultShapeAbsentVersusNullVersusPresent(): void
    {
        $bare = self::decode(self::json(new RateLimitResultDTO(RateLimitResultDTO::DECISION_ALLOW, null, null, 'NORMAL')));
        $emptyMeta = self::decode(self::json(new RateLimitResultDTO(RateLimitResultDTO::DECISION_ALLOW, null, null, 'NORMAL', new RateLimitMetadataDTO())));
        $fullJson = self::json(S::fullResult());
        $full = self::decode($fullJson);

        $this->assertSame(['decision', 'blockLevel', 'retryAfter', 'failureMode', 'metadata'], array_keys($bare));
        $this->assertNull($bare['metadata']); // key present, null
        $this->assertSame(['signal' => null, 'cause' => null, 'context' => null], $emptyMeta['metadata']); // optional members absent, base members null
        $this->assertSame(['signal', 'cause', 'context', 'postPunishmentReentry', 'permit'], array_keys(self::arr($full['metadata'])));
        $this->assertSame(self::markersIn($fullJson), ['SYN263-PERMIT', 'SYN263-REENTRY-ID', 'SYN263-SIGNAL', 'SYN263-CAUSE', 'SYN263-REASON', 'SYN263-SCOPE']);
        $this->record('S04_result_shapes', ['cases' => ['bare result', 'empty metadata', 'full metadata']], 'absent members: postPunishmentReentry and permit when null; present-null members: metadata, signal, cause, context', ['bareKeys' => array_keys($bare), 'bareMetadata' => $bare['metadata'], 'emptyMetadata' => $emptyMeta['metadata'], 'fullMetadataKeys' => array_keys(self::arr($full['metadata'])), 'fullMarkers' => self::markersIn($fullJson)]);
    }

    public function testSignalAndNestedReentryShapes(): void
    {
        $signal = self::decode(self::json(new FailureSignalDTO(FailureSignalDTO::TYPE_CB_OPENED, self::POLICY, S::fullResult()->metadata)));
        $reentry = self::decode(self::json(new PostPunishmentReentryMetadataDTO('SYN263-REENTRY-ID', 1735732800)));

        $this->assertSame(['type', 'policyName', 'metadata'], array_keys($signal));
        $this->assertSame(['id', 'validUntil'], array_keys($reentry));
        $this->record('S05_signal_and_reentry', ['cases' => ['FailureSignalDTO with full metadata', 'PostPunishmentReentryMetadataDTO']], 'documented keys only', ['signalKeys' => array_keys($signal), 'signalMetadataKeys' => array_keys(self::arr($signal['metadata'])), 'reentryKeys' => array_keys($reentry)]);
    }

    public function testNoDtoOfTheResultOrSignalPathHoldsTheRequestContext(): void
    {
        $holders = [];
        foreach ([RateLimitResultDTO::class, RateLimitMetadataDTO::class, FailureSignalDTO::class, PostPunishmentReentryMetadataDTO::class] as $class) {
            foreach ((new \ReflectionClass($class))->getProperties() as $property) {
                $type = $property->getType();
                $names = $type instanceof \ReflectionNamedType ? [$type->getName()] : ($type instanceof \ReflectionUnionType ? array_map(static fn(\ReflectionType $t): string => $t instanceof \ReflectionNamedType ? $t->getName() : '', $type->getTypes()) : []);
                if (\in_array(RateLimitContextDTO::class, $names, true) || \in_array('mixed', $names, true) || \in_array('array', $names, true)) {
                    $holders[] = $class . '::$' . $property->getName() . ' (' . implode('|', $names) . ')';
                }
            }
        }

        $this->assertSame([], $holders);
        $this->record('S06_type_inventory', ['classes' => 'RateLimitResultDTO, RateLimitMetadataDTO, FailureSignalDTO, PostPunishmentReentryMetadataDTO'], 'no property typed RateLimitContextDTO, array or mixed (so the request context cannot be carried by them by type)', ['propertiesThatCouldHoldTheContext' => $holders]);
    }

    public function testNothingIsWrittenToTheObservedChannelsBySerializationOrByARealRuntimeCall(): void
    {
        // The channels observed are the ones PHP itself offers a library: standard output, the PHP error log file and PHP diagnostics.
        // A Host's own logger or log sink is not wired to the runtime here, so nothing about it is concluded (see S14).
        $errorLog = tempnam(sys_get_temp_dir(), 'syn263');
        $this->assertNotFalse($errorLog);
        $previousErrorLog = ini_set('error_log', $errorLog);
        $previousLogErrors = ini_set('log_errors', '1');
        /** @var list<string> $diagnostics */
        $diagnostics = [];
        set_error_handler(static function (int $no, string $str) use (&$diagnostics): bool {
            $diagnostics[] = $str;

            return true;
        });
        ob_start();
        try {
            R::resetFallbackState();
            $healthy = R::build('production', self::POLICY, 'healthy');
            $typed = R::build('production', self::POLICY . '_b', 'typed');
            $results = [];
            foreach ([$healthy, $typed] as $runtimeCase) {
                for ($i = 0; $i < 3; $i++) {
                    $results[] = $runtimeCase->runtime->limit(S::fullContext(), RateLimitCommand::checkOnly($runtimeCase->policyName));
                }
            }
            $serialized = self::json($results); // serialization alone, with no write step
        } finally {
            $output = (string) ob_get_clean();
            restore_error_handler();
            R::resetFallbackState();
        }
        $logged = (string) file_get_contents($errorLog);
        unlink($errorLog);
        ini_set('error_log', (string) $previousErrorLog);
        ini_set('log_errors', (string) $previousLogErrors);

        $this->assertSame('', $output);
        $this->assertSame('', $logged);
        $this->assertSame([], $diagnostics);
        $this->record('S07_no_write_in_observed_channels', ['calls' => '3 on a healthy runtime and 3 on a typed-outage runtime (fallback), full synthetic context', 'observedChannels' => ['stdout', 'php error_log file', 'php notices/warnings'], 'notObserved' => 'any Host logger or sink (none is wired to the runtime in this scenario)'], 'nothing written to the three observed channels', ['stdoutBytes' => \strlen($output), 'errorLogBytes' => \strlen($logged), 'phpDiagnostics' => $diagnostics, 'decisions' => array_map(static fn(RateLimitResultDTO $r): string => $r->decision, $results), 'contextMarkersInSerializedResults' => self::markersIn($serialized)]);
    }

    public function testSignalsReachTheHostEmitterAndTheyReachTheSinkOnlyWhenTheHostEmitterWritesThem(): void
    {
        R::resetFallbackState();
        $sink = new RecordingLogSink();
        $silent = new RecordingFailureSignalEmitter();
        $logging = new LoggingFailureSignalEmitter($sink);
        $silentRuntime = EmitterRuntime::build(self::POLICY . '_s', $silent);
        $loggingRuntime = EmitterRuntime::build(self::POLICY . '_l', $logging);
        for ($i = 0; $i < 12; $i++) {
            $silentRuntime->limit(S::fullContext(), RateLimitCommand::checkOnly(self::POLICY . '_s'));
            $loggingRuntime->limit(S::fullContext(), RateLimitCommand::checkOnly(self::POLICY . '_l'));
        }
        R::resetFallbackState();
        $silentTypes = array_map(static fn(FailureSignalDTO $x): string => $x->type, $silent->getEmitted());
        $loggingTypes = array_map(static fn(FailureSignalDTO $x): string => $x->type, $logging->received);
        $lineMarkers = self::markersIn(implode("\n", $sink->lines));

        $this->assertContains(FailureSignalDTO::TYPE_CB_OPENED, $loggingTypes);
        $this->assertSame($silentTypes, $loggingTypes); // the library hands the same signals to either emitter
        $this->assertCount(\count($logging->received), $sink->lines); // lines exist only because the Host emitter wrote them
        $this->assertSame([], $lineMarkers); // a signal does not carry the request context
        $this->record('S14_signal_emitter_vs_log_write', ['calls' => 12 . ' per runtime, typed outage, full synthetic context', 'emitters' => ['recording only (no write)', 'Host-style emitter that json_encodes the signal and writes it to a sink']], 'the library calls the emitter; a log line exists only where the Host emitter writes one; signals carry no request-context marker', ['signalTypesToRecordingEmitter' => $silentTypes, 'signalTypesToLoggingEmitter' => $loggingTypes, 'sinkLines' => \count($sink->lines), 'sinkLine0' => $sink->lines[0] ?? null, 'contextMarkersInSinkLines' => $lineMarkers]);
    }

    public function testRealRuntimeResultsDoNotCarryTheRequestContext(): void
    {
        R::resetFallbackState();
        $typed = R::build('production', self::POLICY, 'typed');
        $results = [];
        for ($i = 0; $i < 3; $i++) {
            $results[] = $typed->runtime->limit(S::fullContext(), RateLimitCommand::checkOnly(self::POLICY));
        }
        R::resetFallbackState();
        $json = self::json($results);

        $this->assertSame([], self::markersIn($json));
        $this->record('S08_real_results_vs_context', ['calls' => 3, 'backend' => 'typed outage (fallback), full synthetic context'], 'no request-context marker in a serialized real result', ['decisions' => array_map(static fn(RateLimitResultDTO $r): string => $r->decision, $results), 'resultJson' => json_decode($json, true), 'markersFound' => self::markersIn($json)]);
    }

    public function testLoggingTheWholeContextDtoCarriesTheMarkersToTheSinkWhenTheHostWritesIt(): void
    {
        $sink = new RecordingLogSink();
        $this->assertCount(0, $sink->lines); // building the DTO and serializing it do not reach the sink
        $context = S::fullContext();
        $line = self::json($context);
        $this->assertCount(0, $sink->lines); // serialization alone: still nothing
        $sink->write($line); // the Host's write step
        $contextMarkers = array_values(array_filter(array_keys(S::MARKERS), static fn(string $m): bool => !str_starts_with(S::MARKERS[$m], 'metadata.')));

        $this->assertSame($contextMarkers, self::markersIn($sink->lines[0]));
        $this->record('S09_host_logs_whole_context', ['steps' => ['construct', 'json_encode', 'sink->write']], 'markers reach the sink only at the Host write step', ['sinkLinesAfterConstruct' => 0, 'sinkLinesAfterSerialize' => 0, 'sinkLinesAfterWrite' => 1, 'markersInSinkLine' => self::markersIn($sink->lines[0]), 'whichInputsSensitiveByName' => ['headers (Authorization, Cookie)', 'clientFingerprint', 'sessionDeviceId', 'admissionCredential', 'clientInstanceId', 'correlationId', 'accountId', 'ip', 'ua']]);
    }

    public function testLoggingTheWholeResultDtoCarriesNestedMetadataMarkers(): void
    {
        $sink = new RecordingLogSink();
        $sink->write(self::json(S::fullResult()));

        $this->assertSame(['SYN263-PERMIT', 'SYN263-REENTRY-ID', 'SYN263-SIGNAL', 'SYN263-CAUSE', 'SYN263-REASON', 'SYN263-SCOPE'], self::markersIn($sink->lines[0]));
        $this->record('S10_host_logs_whole_result', ['steps' => ['json_encode result with full metadata', 'sink->write']], 'permit and reentry claim id are in the line when metadata is serialized whole', ['markersInSinkLine' => self::markersIn($sink->lines[0])]);
    }

    public function testAllowlistRecordHasOnlyTheNamedFieldsAndNoMarkerFromAnyNestedOrAdmissionSource(): void
    {
        $sink = new RecordingLogSink();
        $context = S::fullContext(); // never handed to the allowlist: the example does not take the context
        $result = S::fullResult();
        $record = AllowlistDiagnosticRecord::fromResult(self::POLICY, $result);
        $sink->write(self::json($record));
        $line = $sink->lines[0];

        $this->assertSame(['policy', 'decision', 'failureMode', 'blockLevel', 'retryAfter'], array_keys($record));
        $this->assertSame(['policy' => self::POLICY, 'decision' => 'HARD_BLOCK', 'failureMode' => 'DEGRADED_MODE', 'blockLevel' => 2, 'retryAfter' => 600], $record);
        $this->assertSame([], self::markersIn($line));
        $this->assertNotSame('', $context->ip);

        $hostile = new RateLimitResultDTO('SYN263-SIGNAL', 1, 1, 'SYN263-CAUSE', $result->metadata);
        $hostileRecord = AllowlistDiagnosticRecord::fromResult(self::POLICY, $hostile);
        $this->assertSame('unrecognized', $hostileRecord['decision']);
        $this->assertSame('unrecognized', $hostileRecord['failureMode']);
        $this->assertSame([], self::markersIn(self::json($hostileRecord)));
        $this->record('S11_allowlist_record', ['input' => 'full synthetic result (nested metadata with markers); the context is not passed'], ['keys' => ['policy', 'decision', 'failureMode', 'blockLevel', 'retryAfter'], 'markersFound' => []], ['line' => $line, 'keys' => array_keys($record), 'markersFound' => self::markersIn($line), 'hostileEnumLine' => self::json($hostileRecord), 'hostileMarkersFound' => self::markersIn(self::json($hostileRecord))]);
    }

    public function testDenylistApproachIsAControlThatLeaksNestedMarkers(): void
    {
        $context = S::fullContext();
        $serialized = self::decode(self::json($context));
        unset($serialized['headers'], $serialized['admissionCredential']); // a Host that removes only the fields it thought of
        $line = self::json($serialized);
        $found = self::markersIn($line);

        $this->assertContains('SYN263-FP-DEEP', $found);
        $this->assertContains('SYN263-INSTANCE', $found);
        $this->assertNotContains('SYN263-HEADER-AUTH', $found);
        $this->record('S12_denylist_control', ['approach' => 'json_encode then unset headers and admissionCredential'], 'markers of the fields not removed remain (control, not a recommendation)', ['markersFound' => $found]);
    }

    public function testIntegerWidthDoesNotChangeTheSerializedShape(): void
    {
        $result = new RateLimitResultDTO(RateLimitResultDTO::DECISION_SOFT_BLOCK, 1, PHP_INT_MAX, 'NORMAL');
        $json = self::json(AllowlistDiagnosticRecord::fromResult(self::POLICY, $result));

        $this->assertSame(PHP_INT_MAX, self::decode($json)['retryAfter']);
        $this->record('S13_int_width', ['retryAfter' => 'PHP_INT_MAX'], 'integer round-trips unchanged on this width', ['phpIntMax' => PHP_INT_MAX, 'line' => $json]);
    }

    /**
     * @param array<mixed> $inputs
     * @param array<mixed>|string $expected
     * @param array<mixed> $observed
     */
    private function record(string $scenario, array $inputs, array|string $expected, array $observed): void
    {
        $file = getenv('STUDY_263_EVIDENCE');
        if (!\is_string($file) || $file === '') {
            return;
        }
        $line = json_encode(['scenario' => $scenario, 'phpIntSize' => PHP_INT_SIZE, 'phpVersion' => PHP_VERSION, 'inputs' => $inputs, 'expected' => $expected, 'observed' => $observed], JSON_THROW_ON_ERROR);
        $this->assertNotFalse(file_put_contents($file, $line . "\n", FILE_APPEND | LOCK_EX));
    }
}
