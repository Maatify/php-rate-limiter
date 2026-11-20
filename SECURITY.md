# Security Policy

[![Maatify Rate Limiter](https://img.shields.io/badge/Maatify-Rate%20Limiter-blue?style=for-the-badge)](https://github.com/Maatify/php-rate-limiter)
[![Maatify Ecosystem](https://img.shields.io/badge/Maatify-Ecosystem-9C27B0?style=for-the-badge)](https://github.com/Maatify)

## Current status

This document represents Release Candidate `1.0.0-rc.4` and does not claim that
it has been published. No Published Stable release line exists, and this RC does
not establish a Stable support line. No Stable release is represented as published.
No security support promise or release date is implied by this document.

## Logging diagnostics

The package does not write logs. A Host that logs rate-limit results should build the record field by field and not serialize the result, its metadata, or the request context. See [Host-Owned Diagnostic Logging](docs/guides/USAGE_GUIDE.md#host-owned-diagnostic-logging).

## Reporting a vulnerability

Report sensitive security vulnerabilities privately to
`support@maatify.dev`. Do not publish exploit details, credentials, or other
sensitive information in a public issue. Use public repository issues only for
non-sensitive defects and general discussion.

This route reflects the repository's current security reporting contract. Any
response or remediation timeline is subject to the applicable written
authorization or license agreement.
