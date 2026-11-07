<p align="center">
  <img src="https://raw.githubusercontent.com/pikaid/pikaid-php/refs/heads/main/logo.png" alt="pikaid" width="300" />
</p>


<p align="center">
  <strong>Small · Sortable · Secure ID generator</strong>
</p>


<div align="center">


[![License: MIT](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)
[![PHP](https://img.shields.io/badge/PHP-modern-777bb4.svg)](#)

</div>

**pikaid** is a lightweight PHP library for generating **compact, chronologically sortable, collision-resistant IDs** for databases, APIs, and distributed systems.

This is the **official PHP implementation** of the immutable, unversioned [pikaid format](https://github.com/pikaid/pikaid-specs), documented in specification revision **1.0.4**.

## ✨ Why pikaid?

- **Short:** Compact, readable identifiers for URLs, logs, and API resources.
- **Sortable:** Lexicographical ordering by creation timestamp, with **one-second precision**.
- **Collision-resistant:** Cryptographically secure randomness makes collisions extremely unlikely across independent generators, but never mathematically impossible.
- **Storage-friendly:** Use human-readable `CHAR(26)` or compact `BINARY(17)`.
- **Distributed-friendly:** Generate IDs independently without requiring a central sequence allocator.

## 🧬 Identifier format

Each pikaid is a **26-character lowercase Base36 identifier**, composed of:

- **7-character timestamp** (seconds since the Unix epoch)
- **19-character cryptographically secure randomness**

The binary representation uses **5 bytes for the timestamp** (unsigned 40-bit, big-endian) and **12 bytes for randomness**, for a total of **17 bytes**.

![pikaid identifier anatomy](https://raw.githubusercontent.com/pikaid/pikaid-specs/v1.0.4/structure.svg)

> **Ordering guarantee:** IDs from different seconds sort chronologically. IDs created within the same second sort by their random suffix, **not** by generation order.

> **Security note:** pikaid values are identifiers, **not secrets**. Do not use them as session credentials, password-reset tokens, authorization tokens, or API keys. The 96-bit random suffix makes collisions probabilistically negligible, but not mathematically impossible, and same-second ordering is not an issuance or audit-order guarantee.

## ⚙️ Requirements

- PHP **7.3+ on 64-bit platforms** (PHP 8 recommended)
- `ext-gmp` *(recommended for best performance)* or `ext-bcmath` *(optional)*
- If neither extension is installed, a pure-PHP fallback is used.

## 📦 Installation

Install via Composer:

```bash
composer require pikaid/pikaid-php
```

Include Composer's autoloader in your project:

```php
require 'vendor/autoload.php';
```

## 🚀 Basic Usage

```php
<?php

use Pikaid\Pikaid;

// Generate a new Pikaid string
$id = Pikaid::generate();
echo "ID: $id\n"; // e.g. 0swct4q01ch83h91onl6l47vb6

// Validate
if (Pikaid::isValid($id)) {
    echo "Valid ID!\n";
}

// Parse components
$data = Pikaid::parse($id);
echo "Timestamp: " . $data['timestamp']->format('Y-m-d H:i:s') . " UTC\n";
echo "Randomness (hex): {$data['randomness']}\n";
```

## 💡 Example Use Case

Imagine a web app where users post messages or place orders. Each user, message, or order needs a unique identifier.

Traditional UUIDv4 values are longer and random, so they do not naturally sort by creation time. Independently generated numeric sequences can also conflict across servers unless the generators coordinate.

With pikaid, you can generate short, collision-resistant identifiers independently and sort them by timestamp (to the nearest second).

Typical uses include:

- Identifying **users**, **orders**, **invoices**, and **API resources**.
- Naming **uploaded files** with opaque identifiers.
- Tagging **events** and **logs** across distributed systems.

## 📚 Canonical specification

The pikaid identifier format is immutable and unversioned.

- [Canonical specification](https://github.com/pikaid/pikaid-specs)
- [Specification revision 1.0.4](https://github.com/pikaid/pikaid-specs/tree/v1.0.4)

---

## 🧩 API Reference

### **`Pikaid::generate(): string`**

Generate a new **26-character string** ID.

* Layout: `[7 chars timestamp][19 chars randomness]`
* Sortable by second.

```php
$id = Pikaid::generate();
```

---

### **`Pikaid::generateBinary(): string`**

Generate the **binary form** directly (`BINARY(17)`).

* Layout: `[5 bytes timestamp (uint40, big-endian)][12 bytes entropy]`.

```php
$bin = Pikaid::generateBinary();
```

---

### **`Pikaid::toBinary(string $id): string`**

Convert a **string ID** to its **binary (17 bytes)** representation.
Throws `PikaidException` (a subtype of `InvalidArgumentException`) if the input is invalid.

```php
$bin = Pikaid::toBinary($id);
```

---

### **`Pikaid::fromBinary(string $bin): string`**

Convert **binary (17 bytes)** back into a **26-char string**.
Throws `PikaidException` (a subtype of `InvalidArgumentException`) for an invalid binary length or timestamp.

```php
$id = Pikaid::fromBinary($bin);
```

---

### **`Pikaid::isValid(string $id): bool`**

Check if the given string is a **fully valid pikaid** (not only well-formed).

* Must be **26 chars** long
* Must use only lowercase Base36 characters and decode to a randomness value within the 96-bit range

```php
if (Pikaid::isValid($id)) {
    echo "Valid ID!";
}
```

**`Pikaid::isWellFormed(string $id): bool`** checks only length and character set. It does not verify the 96-bit randomness bound; use `isValid()` for full validation.

---

### **`Pikaid::parse(string $id): array`**

Parse a **string ID** into its components:

* `timestamp` → `DateTimeImmutable` (UTC)
* `randomness` → lowercase hex string (24 chars = 12 bytes); `entropy_hex` remains as a compatibility alias

Throws `PikaidException` (a subtype of `InvalidArgumentException`) on invalid input.

```php
$info = Pikaid::parse($id);
/*
[
  'timestamp' => DateTimeImmutable(...),
  'randomness' => 'a1b2c3d4e5f6a7b8c9d0e1f2'
]
*/
```

---

### **`Pikaid::fromDateTime(DateTimeInterface $t): string`**

Generate a **string ID** for a specific timestamp (seconds precision).

```php
$id = Pikaid::fromDateTime(new DateTimeImmutable('@1234567890'));
```

---

### Additional public methods

All methods accepting a pikaid string perform strict validation. Invalid input raises `PikaidException`, which extends `InvalidArgumentException`.

| Method                                                       | Purpose                                                      |
| ------------------------------------------------------------ | ------------------------------------------------------------ |
| `Pikaid::generateWithTimestamp(int $timestamp): string`      | Generate an ID using a specified Unix timestamp in seconds and fresh cryptographic randomness. |
| `Pikaid::generateBinaryWithTimestamp(int $timestamp): string` | Generate the 17-byte representation for the specified timestamp with fresh randomness. |
| `Pikaid::validateOrThrow(string $id): array`                 | Validate strictly and return `['timestamp' => int, 'entropy' => string]`, where `entropy` contains 12 raw bytes. |
| `Pikaid::decode(string $id): array`                          | Return `[int $timestamp, string $entropyBytes]` without allocating a `DateTimeImmutable`. |
| `Pikaid::getTimestamp(string $id): int`                      | Extract the Unix timestamp in seconds.                       |
| `Pikaid::getEntropyBytes(string $id): string`                | Extract exactly 12 randomness bytes.                         |
| `Pikaid::getEntropyHex(string $id): string`                  | Extract the randomness as 24 lowercase hexadecimal characters. |
| `Pikaid::compare(string $a, string $b): int`                 | Strictly validate and compare identifiers using canonical bytewise string ordering; returns `-1`, `0`, or `1`. |

`compare()` sorts by timestamp across different seconds. Within the same second, comparison follows the random suffix, **not generation order**.

---

## 🔄 Order Guarantee

* String and binary representations **sort lexicographically by second**:

```php
$id1 = Pikaid::generate();
sleep(1);
$id2 = Pikaid::generate();
assert(strcmp($id1, $id2) < 0); // different timestamps: earlier sorts first
```

---

## 🛢 Storage Recommendations

Pikaid is designed to be **compact and index-friendly**, with a predictable layout:

| Representation | Size | Sortable | Recommended for                        |
| -------------- | ---- | -------- | -------------------------------------- |
| **BINARY(17)** | 17B  | Yes      | High-performance storage and indexing  |
| **CHAR(26)**   | 26B  | Yes      | Readability in SQL, debugging, or logs |

---

### 🔹 Option 1: Store as `BINARY(17)` (Recommended)

Binary form stores exactly **17 bytes**:

```
[5 bytes timestamp (uint40, big-endian)][12 bytes entropy]
```

#### Table Definition

```sql
CREATE TABLE pika_events (
  id BINARY(17) NOT NULL, -- Primary key
  payload JSON NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  -- Optional: Extract timestamp for range queries and indexing
  ts_seconds BIGINT UNSIGNED
    AS ( ORD(SUBSTR(id, 1, 1)) * 4294967296
      + CONV(HEX(SUBSTR(id, 2, 4)), 16, 10) ) STORED,
  PRIMARY KEY (id),
  KEY idx_ts_seconds (ts_seconds),
  KEY idx_created_at (created_at)
) ENGINE=InnoDB;
```

#### Insert Example (PHP)

```php
use Pikaid\Pikaid;

// Generate binary ID and store it
$binId = Pikaid::generateBinary();
$stmt = $pdo->prepare('INSERT INTO pika_events (id, payload) VALUES (?, ?)');
$stmt->execute([$binId, json_encode(['event' => 'signup'])]);
```

#### Select Example (PHP)

```php
$stmt = $pdo->query('SELECT id, ts_seconds FROM pika_events ORDER BY id ASC');
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $stringId = Pikaid::fromBinary($row['id']); // Convert back to string
    echo $stringId . ' @ ' . gmdate('c', (int)$row['ts_seconds']) . PHP_EOL;
}
```

#### Benefits

* Small index size = better performance
* Binary comparison matches chronological order
* Perfect for `PRIMARY KEY` or clustered indexes

---

### 🔹 Option 2: Store as `CHAR(26)` (String)

If you need IDs to remain human-readable directly in SQL queries, store the string form.

#### Table Definition

```sql
CREATE TABLE pika_events_str (
  id CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, -- Primary key
  payload JSON NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  -- Optional: Extract timestamp for range queries and indexing
  ts_seconds BIGINT UNSIGNED
    AS (CONV(SUBSTR(id, 1, 7), 36, 10)) STORED,
  PRIMARY KEY (id),
  KEY idx_ts_seconds (ts_seconds),
  KEY idx_created_at (created_at)
) ENGINE=InnoDB;
```

#### Insert Example (PHP)

```php
use Pikaid\Pikaid;

$id = Pikaid::generate();
$stmt = $pdo->prepare('INSERT INTO pika_events_str (id, payload) VALUES (?, ?)');
$stmt->execute([$id, json_encode(['event' => 'login'])]);
```

#### Select Example (PHP)

```php
$stmt = $pdo->query('SELECT id, ts_seconds FROM pika_events_str ORDER BY id ASC');
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    echo $row['id'] . ' @ ' . gmdate('c', (int)$row['ts_seconds']) . PHP_EOL;
}
```

#### Notes

* Slightly larger storage and index compared to `BINARY(17)`
* Ideal for debugging or manual inspection of IDs

---

### 🔄 Order Guarantee

Both `BINARY(17)` and `CHAR(26)` maintain **natural chronological order**:

```php
$id1 = Pikaid::generate();
sleep(1);
$id2 = Pikaid::generate();

assert(strcmp($id1, $id2) < 0); // different timestamps: earlier sorts first
assert(strcmp(Pikaid::toBinary($id1), Pikaid::toBinary($id2)) < 0); // Binary order too
```

---

## 📄 License

pikaid-php is open-source software licensed under the **MIT License**.

You may use, copy, modify, distribute, and sublicense this library, including in commercial and proprietary applications, subject to the license terms.

See [LICENSE](LICENSE) for the full legal text.

---

## ✉️ Contact

For licensing or project-related questions: [larananas.dev@proton.me](mailto:larananas.dev@proton.me)
