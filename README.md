<p align="center">
  <h1>PikaID</h1>
</p>

<p align="center">
  <img alt="Static Badge" src="https://img.shields.io/badge/PikaID-%20PHP-blue?style=for-the-badge&logo=php">
  <img alt="Static Badge" src="https://img.shields.io/badge/Unique-%20ID%20Generator-green?style=for-the-badge&logo=hashnode">
  <img alt="Static Badge" src="https://img.shields.io/badge/Sortable-%20Chronological-orange?style=for-the-badge&logo=clockify">
  <img alt="Static Badge" src="https://img.shields.io/badge/Secure-%20Crypto%20Entropy-red?style=for-the-badge&logo=securityscorecard">
  <img alt="Static Badge" src="https://img.shields.io/badge/License-%20MIT-black?style=for-the-badge">
</p>


**PikaID** is a tiny PHP library that creates **unique, short, and sortable IDs** —  
a modern alternative to UUIDs for your databases, APIs, and distributed systems.


## 💡 Example Use Case

Imagine you’re building a **web app** where users can post messages or create orders.  
Each new item needs a **unique ID** so your system can recognize it.

If you use normal numbers (`1, 2, 3…`), you can have **conflicts** when several servers create data at the same time.  
If you use UUIDs, you get **long, messy IDs** that are hard to read and don’t follow creation time.

**PikaID solves this:**
- Every new ID is **unique**, even if many servers create them at once.  
- IDs are **short**, so they look clean in URLs or logs.  
- They are **time-ordered**, so when you list data, the newest appears last or first automatically.

You can use PikaID to:
- Identify **users**, **orders**, **invoices**, or **API resources**.  
- Name **uploaded files** safely.  
- Tag **events** or **logs** across distributed systems.  

> **In short:** PikaID gives you clean, safe, and sortable IDs for anything that needs a unique reference.





<p align="center">
  <img src="https://raw.githubusercontent.com/pikaid/pikaid-php/refs/heads/main/logo.png" alt="pikaid" width="300" />
</p>

# Pikaid (PHP)
**Small · Sortable · Secure ID generator**

This is the **official PHP implementation.** Fully compliant with v1.0.1.

Pikaid is a **26-character, lowercase Base36 identifier**, composed of:
- **7-character timestamp** (seconds since epoch)
- **19-character cryptographically secure randomness**

It’s a **modern, compact alternative** to UUID and ULID:
- Lexicographically sortable
- Collision-resistant
- Compact binary form (`BINARY(17)`)

![pikaid structure](https://raw.githubusercontent.com/pikaid/pikaid-php/refs/heads/main/structure.png)

---

## 📚 Specifications & Benchmarks
See the full technical specs and benchmarks at
[**pikaid/pikaid-specs**](https://github.com/pikaid/pikaid-specs)

---

## ⚙️ Requirements
- PHP **7.4+** (PHP 8 recommended)
- `ext-gmp` *(recommended for best performance)* or `ext-bcmath` *(optional)*
- If neither extension is installed, a pure-PHP fallback is used.

---

## 📦 Installation

Install via Composer:

```bash
composer require pikaid/pikaid-php
```

Include Composer's autoloader in your project:

```php
require 'vendor/autoload.php';
```

---

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
Throws `InvalidArgumentException` if the input is not a valid 26-character Pikaid or if the timestamp is out of range.

```php
$bin = Pikaid::toBinary($id);
```

---

### **`Pikaid::fromBinary(string $bin): string`**

Convert **binary (17 bytes)** back into a **26-char string**.
Throws `InvalidArgumentException` if the binary length isn’t exactly 17 bytes.

```php
$id = Pikaid::fromBinary($bin);
```

---

### **`Pikaid::isValid(string $id): bool`**

Check if the given string is a **valid Pikaid**.

* Must be **26 chars** long
* Must match regex: `/^[0-9a-z]{26}$/`

```php
if (Pikaid::isValid($id)) {
    echo "Valid format!";
}
```

---

### **`Pikaid::parse(string $id): array`**

Parse a **string ID** into its components:

* `timestamp` → `DateTimeImmutable` (UTC)
* `randomness` → lowercase hex string (24 chars = 12 bytes)

Throws `InvalidArgumentException` on invalid input.

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

## 🔄 Order Guarantee

* String and binary representations **sort lexicographically by second**:

```php
$id1 = Pikaid::generate();
sleep(1);
$id2 = Pikaid::generate();
assert($id1 < $id2); // always true
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

assert($id1 < $id2); // String order is chronological
assert(strcmp(Pikaid::toBinary($id1), Pikaid::toBinary($id2)) < 0); // Binary order too
```

---

## 📜 License

Pikaid is released under the **MIT License**.

---
