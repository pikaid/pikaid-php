<?php

declare(strict_types=1);

/**
 * Executes the runnable examples from README.md in isolated PHP processes.
 *
 * Blocks are extracted from the README itself (so documentation drift breaks
 * this check), limited to self-contained examples, and run with the installed
 * autoloader. `assert()` calls are rewritten to a local helper because Zend
 * assertions may be compiled out in production INI configurations.
 */

$root = dirname(__DIR__);
$readmePath = $root . '/README.md';
$readme = file_get_contents($readmePath);
if ($readme === false) {
    throw new RuntimeException('Cannot read README.md.');
}

if (preg_match_all('/```php\r?\n(.*?)```/s', $readme, $matches) === false) {
    throw new RuntimeException('Cannot parse README fenced code blocks.');
}

/**
 * A block is runnable when it is self-contained: it must not need an external
 * database handle, an external `require`, or variables created by an earlier
 * block.
 */
$isSelfContained = static function (string $block): bool {
    if (trim($block) === '') {
        return false;
    }
    foreach (['$pdo', "require 'vendor", 'require "vendor'] as $forbidden) {
        if (strpos($block, $forbidden) !== false) {
            return false;
        }
    }
    if (strpos($block, 'Pikaid::') === false) {
        return false;
    }

    // A block may use $id / $bin only when it creates that variable itself.
    if (strpos($block, '$id') !== false && preg_match('/\$id\d*\s*=\s*Pikaid::(generate|fromDateTime)/', $block) !== 1) {
        return false;
    }
    if (strpos($block, '$bin') !== false && preg_match('/\$bin\s*=\s*Pikaid::(generateBinary|toBinary)/', $block) !== 1) {
        return false;
    }

    return true;
};

$examples = [];
foreach ($matches[1] as $block) {
    if ($isSelfContained($block)) {
        $examples[] = $block;
    }
}

if (count($examples) < 3) {
    throw new RuntimeException('Expected at least 3 runnable README examples, found ' . count($examples) . '.');
}

$autoload = $root . '/vendor/autoload.php';
if (!is_file($autoload)) {
    throw new RuntimeException('Missing vendor/autoload.php; run composer install first.');
}

$failures = [];
foreach ($examples as $index => $block) {
    $body = preg_replace('/^\s*<\?php\s*/', '', $block);
    $body = preg_replace('/^\s*use Pikaid\\\\Pikaid;\s*$/m', '', $body);
    $body = str_replace('assert(', '__readmeAssert(', $body);

    $runner = "<?php\n"
        . "declare(strict_types=1);\n"
        . "require " . var_export($autoload, true) . ";\n"
        . "use Pikaid\\Pikaid;\n"
        . "function __readmeAssert(\$condition, \$message = 'README assertion failed'): void {\n"
        . "    if (!\$condition) { throw new RuntimeException((string) \$message); }\n"
        . "}\n"
        . $body . "\n"
        . "if (isset(\$id)) {\n"
        . "    __readmeAssert(is_string(\$id) && strlen(\$id) === 26, 'example \$id is 26 characters');\n"
        . "    __readmeAssert(Pikaid::isValid(\$id), 'example \$id is valid');\n"
        . "}\n"
        . "if (isset(\$id1, \$id2)) {\n"
        . "    __readmeAssert(strcmp(\$id1, \$id2) < 0, 'generated IDs are ordered');\n"
        . "}\n"
        . "if (isset(\$bin)) {\n"
        . "    __readmeAssert(is_string(\$bin) && strlen(\$bin) === 17, 'example \$bin is 17 bytes');\n"
        . "}\n";

    $temp = tempnam(sys_get_temp_dir(), 'pikaid-readme-');
    if ($temp === false) {
        throw new RuntimeException('Cannot create a temporary file.');
    }
    file_put_contents($temp, $runner);

    $command = escapeshellarg(PHP_BINARY) . ' -d error_reporting=E_ALL ' . escapeshellarg($temp) . ' 2>&1';
    $output = [];
    $exit = 0;
    exec($command, $output, $exit);
    @unlink($temp);

    if ($exit !== 0) {
        $failures[] = 'example #' . ($index + 1) . ' exited with ' . $exit . ':' . PHP_EOL . implode(PHP_EOL, $output);
    }
}

if ($failures !== []) {
    throw new RuntimeException("README example verification failed:\n" . implode("\n\n", $failures));
}

echo 'PASS: ' . count($examples) . " runnable README examples executed\n";
