# Changelog

Changes to the **PHP implementation** are recorded here. The pikaid identifier format remains immutable and unversioned; specification revisions are separate from PHP package releases.

## Unreleased

### Added

- Independently derived conformance matrix (`tests/vectors/conformance-matrix.json`) covering valid boundaries, overflow, invalid characters, Unicode/zero-width input, wrong types and ordering, pinned by SHA-256 and cross-checked against a decimal-string oracle rather than the production encoder.
- Backend parity tests that run the identical matrix through the GMP, BCMath and genuine no-extension pure-PHP arithmetic paths in separate processes and assert the reported backend selection and identical results.
- Public API lock test freezing all public method signatures, constants, exception codes and exception factories, plus the cross-SDK capability table in the README.
- Coverage measurement restricted to `src/` with an enforceable regression gate (statements, methods, and branches when the driver reports them).
- Packaging hygiene check for `composer archive` output and an external consumer smoke test that installs the archive into an isolated project.
- README example extraction and execution, and a repository-wide PHP lint step in CI.
- CI jobs now cover PHP 8.3/8.4/8.5, Xdebug path coverage, legacy PHP 7.3/7.4, and the packaging/consumer checks.

### Changed

- README documents specification revision 1.0.4 pinning, the verification performed in CI, current support status (8.3/8.4/8.5 supported; 7.3/7.4 legacy and end-of-life), and the historical license change.
- `archive.exclude` now keeps tests, benchmarks, CI configuration and development artifacts out of distributable archives.

### Fixed

- Keep local `/.ai/` working files and other development artifacts out of `composer archive` output. The overbroad `/.??` ignore rule is replaced by an explicit `/.ai/` rule, and `archive.exclude` now covers local tooling, caches, `tests/`, `benchmarks/` and `vendor/`. Published Git/Packagist archives were already unaffected because `.ai/` is untracked.
- Add behavioral coverage for `generateBinary()` and the reserved invalid-binary-type exception factory so previously unexecuted paths are exercised.

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
