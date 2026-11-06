# pikaid conformance and regression tests

The identifier format is **immutable and unversioned**. The official normative reference is [pikaid-specs revision 1.0.4](https://github.com/pikaid/pikaid-specs/tree/v1.0.4).

`tests/vectors/pikaid-v1.json` is a historical filename, **not** an identifier format version. The vectors are retained for compatibility with existing test harnesses. Their metadata records the referenced specification revision. The format, encoded strings, and binary representations are unchanged.

## Supported runtimes and test tooling

The library requires **64-bit PHP 7.3+**. PHPUnit 12 requires PHP 8.3+, so its developer dependencies are installed only on newer PHP. The standalone harness also runs on PHP 7.3/7.4 without Composer. GitHub Actions checks PHP 7.3/7.4 with the standalone harness and runs both harnesses on PHP 8.3/8.4.

## Run PHPUnit

```bash
composer install
vendor/bin/phpunit --configuration phpunit.xml.dist
```

## Standalone validation (no Composer or PHPUnit required)

```bash
php tests/verify.php
php -n tests/verify.php
```

This runs known-answer vectors, invalid inputs, boundary cases, and randomized round-trip/order checks using the currently installed PHP runtime. The final line reports the runtime and the active conversion backends, for example `PASS: ... (PHP 8.3.0; GMP yes; BCMath yes)`, so the path actually exercised is observable.

`php -n` ignores `php.ini`, so extensions loaded from configuration are absent. It does **not** guarantee the pure-PHP fallback when GMP/BCMath are compiled statically into the binary (some distribution and Homebrew builds). Check before relying on it:

```bash
php -n -r 'printf("gmp=%s bcmath=%s\n", extension_loaded("gmp") ? "yes" : "no", extension_loaded("bcmath") ? "yes" : "no");'
```

Use a runtime that reports `gmp=no bcmath=no` for genuine pure-PHP coverage, or force the backend through the parity test in `tests/PikaidBehaviorTest.php`. PHPUnit provides the maintained regression suite.

## Porting the vectors

Other language implementations may reuse `tests/vectors/pikaid-v1.json` and `tests/vectors/pikaid-v1.schema.json`. They must verify successful string-to-binary and binary-to-string conversions, timestamp/randomness extraction, and rejection of malformed or out-of-range values. Do not infer an identifier version from the vector filenames.
