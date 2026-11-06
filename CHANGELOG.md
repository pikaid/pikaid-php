# Changelog

Changes to the **PHP implementation** are recorded here. The pikaid identifier format remains immutable and unversioned; specification revisions are separate from PHP package releases.

## 1.2.0 - 2026-11-06

### Changed

- Adopt the MIT License in place of LGPL-3.0-or-later for the PHP library.
- Align generation, strict validation, binary conversion, and ordering with canonical specification revision 1.0.4 without changing valid identifier encodings.
- Make `compare()` use explicit bytewise comparison and make BCMath conversion independent of ambient `bcscale()`.
- Preserve the previously declared PHP 7.3 minimum (on 64-bit platforms) without requiring PHP 7.4 typed properties.
- Document the complete public API and link to the immutable v1.0.4 specification snapshot.
- Let Packagist derive package versions from Git tags instead of declaring a fixed `version` in `composer.json`.

### Added

- `PikaidException` with descriptive error codes, and additional validation, extraction, and timestamp-specific public methods.
- Preserve the existing `parse()['randomness']` key and add `entropy_hex` as a compatibility alias.
- Portable conformance vectors, additional overflow cases, a standalone verification harness, and PHPUnit regression coverage.
- GitHub Actions checks for PHP 7.3/7.4 (standalone) and PHP 8.3/8.4 (PHPUnit and standalone).

### Notes for consumers

- `PikaidException` extends `InvalidArgumentException`, so existing catches of that parent type continue to work.
- `isValid()` now validates the full identifier, including the 96-bit randomness bound, whereas older releases only checked the string shape. Use `isWellFormed()` for shape-only checks.
- `parse()` adds a compatibility key; consumers depending on an exact two-key array shape should review their assumptions.
