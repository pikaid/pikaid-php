<p align="center">
  <img src="logo.png" alt="pikaid" width="300" />
</p>

# Pikaid (PHP)

**Small · Sortable · Secure ID generator**

Pikaid is a 26-character, lowercase Base36 identifier composed of a 7-character timestamp and a 19-character cryptographically secure randomness. It provides a modern, compact alternative to UUID and ULID, with built-in lexicographical sortability and strong collision resistance.

## Specifications, Concepts, Design & Benchmarks
Full specifications and other information are avalible at [pikaid/pikaid-specs](https://github.com/pikaid/pikaid-specs).

## Requirements

* PHP 7.4 or higher
* ext-gmp or ext-bcmath or none of them (optional, recommended for performance, GMP is better)

## Installation

Install via Composer:

```bash
composer require pikaid/pikaid-php
```

Then include Composer's autoloader in your project:

```php
require 'vendor/autoload.php';
```

## Basic Usage

```php
<?php

use Pikaid\Pikaid;

// Generate a new Pikaid
$id = Pikaid::generate();
echo "New ID: $id\n"; // e.g. 0swct4q01ch83h91onl6l47vb6

// Validate an existing ID
if (Pikaid::isValid($id)) {
    echo "ID is valid!\n";
} else {
    echo "Invalid ID format.\n";
}

// Parse a Pikaid into its components
try {
    $result = Pikaid::parse($id);
    /** @var DateTimeImmutable $timestamp */
    $timestamp = $result['timestamp'];
    $randomHex = $result['randomness'];

    echo "Created at: " . $timestamp->format('Y-m-d H:i:s') . " UTC\n";
    echo "Randomness (hex): $randomHex\n";
} catch (InvalidArgumentException $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
```

## API Reference

### `Pikaid::generate(): string`

Generates a new 26-character Pikaid.

### `Pikaid::isValid(string $id): bool`

Checks whether the given string matches the Pikaid format.

### `Pikaid::parse(string $id): array`

Parses a Pikaid into:

* `timestamp`: `DateTimeImmutable` (UTC)
* `randomness`: `string` (hex representation)

Throws `InvalidArgumentException` if the format is invalid.


## License

Pikaid is released under the MIT License.
