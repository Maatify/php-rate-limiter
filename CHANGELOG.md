# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added
- Standalone Composer package foundation for `maatify/php-rate-limiter`
- Initial package metadata and CI quality gate

### Fixed
- Fixed fallback UA double-normalization so local fallback receives raw UA and applies normalization once.
- Fixed local fallback GC so cleanup removes only expired fixed-window buckets and preserves active buckets until natural rollover.

### Changed
- Package licensing established as proprietary by Owner Decision before first release
