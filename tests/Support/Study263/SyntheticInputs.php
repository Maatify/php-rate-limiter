<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\Support\Study263;

use Maatify\RateLimiter\DTO\PostPunishmentReentryMetadataDTO;
use Maatify\RateLimiter\DTO\RateLimitContextDTO;
use Maatify\RateLimiter\DTO\RateLimitContextMetadataDTO;
use Maatify\RateLimiter\DTO\RateLimitMetadataDTO;
use Maatify\RateLimiter\DTO\RateLimitResultDTO;

/**
 * #263 study: synthetic inputs whose every sensitive-looking value carries a unique, greppable SYN263 marker.
 * No value is a real credential, header, address of a person or token; the IP is a documentation address (RFC 5737).
 */
final class SyntheticInputs
{
    public const IP = '203.0.113.63';

    /** @var array<string, string> marker => where it is placed */
    public const MARKERS = [
        '203.0.113.63' => 'ip',
        'SYN263-UA' => 'ua',
        'SYN263-ACCOUNT' => 'accountId',
        'SYN263-FP-CANVAS' => 'clientFingerprint.canvas',
        'SYN263-FP-DEEP' => 'clientFingerprint.nested.deep',
        'SYN263-SESSION-DEVICE' => 'sessionDeviceId',
        'SYN263-HEADER-AUTH' => 'headers.Authorization',
        'SYN263-COOKIE-A' => 'headers.Cookie[0]',
        'SYN263-COOKIE-B' => 'headers.Cookie[1]',
        'SYN263-CORRELATION' => 'correlationId',
        'SYN263-INSTANCE' => 'clientInstanceId',
        'SYN263-ADMISSION-CRED' => 'admissionCredential',
        'SYN263-PERMIT' => 'metadata.permit',
        'SYN263-REENTRY-ID' => 'metadata.postPunishmentReentry.id',
        'SYN263-SIGNAL' => 'metadata.signal',
        'SYN263-CAUSE' => 'metadata.cause',
        'SYN263-REASON' => 'metadata.context.reason',
        'SYN263-SCOPE' => 'metadata.context.scope',
    ];

    /** Required inputs only. */
    public static function minimalContext(): RateLimitContextDTO
    {
        return new RateLimitContextDTO(self::IP, 'SYN263-UA');
    }

    /** Every input set, admission inputs included. */
    public static function fullContext(): RateLimitContextDTO
    {
        return new RateLimitContextDTO(
            self::IP,
            'SYN263-UA',
            'SYN263-ACCOUNT',
            ['canvas' => 'SYN263-FP-CANVAS', 'nested' => ['deep' => 'SYN263-FP-DEEP']],
            'SYN263-SESSION-DEVICE',
            true,
            ['Authorization' => 'SYN263-HEADER-AUTH', 'Cookie' => ['SYN263-COOKIE-A', 'SYN263-COOKIE-B']],
            true,
            'SYN263-CORRELATION',
            'SYN263-INSTANCE',
            'SYN263-ADMISSION-CRED',
        );
    }

    /** Context with the admission inputs supplied but empty strings (present, not null). */
    public static function emptyAdmissionContext(): RateLimitContextDTO
    {
        return new RateLimitContextDTO(self::IP, 'SYN263-UA', null, null, null, false, [], false, null, '', '');
    }

    /** A result whose nested metadata carries a marker in every member, including the optional ones. */
    public static function fullResult(): RateLimitResultDTO
    {
        return new RateLimitResultDTO(
            RateLimitResultDTO::DECISION_HARD_BLOCK,
            2,
            600,
            'DEGRADED_MODE',
            new RateLimitMetadataDTO(
                'SYN263-SIGNAL',
                'SYN263-CAUSE',
                new RateLimitContextMetadataDTO('SYN263-REASON', 'SYN263-SCOPE'),
                new PostPunishmentReentryMetadataDTO('SYN263-REENTRY-ID', 1735732800),
                'SYN263-PERMIT',
            ),
        );
    }
}
