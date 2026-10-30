# Universal test vectors for PikaID

This folder is designed to be copied into any language implementation.

## What is "universal" here?

- The **source of truth** is `tests/vectors/pikaid-v1.json`.
- Each language implements a tiny harness that reads the vectors and asserts:
  - string <-> binary conversion is reversible
  - parsing extracts the expected timestamp + entropy
  - invalid inputs are rejected

## PHP (this repo)

Run:

```bash
composer install
vendor/bin/phpunit
```

The PHP runner is `tests/PikaidConformanceTest.php`.

## Porting to another language

1. Copy `tests/vectors/pikaid-v1.json` (+ schema if you want).
2. Write tests that iterate over:
   - `valid[]`
   - `invalid.string[]`
   - `invalid.binary[]`

Keep the test runner idiomatic for the language.
