<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Native regression tests for the test-only JSONL adapter used by the shared
 * PikaID conformance suite (tests/official/adapter.php).
 *
 * The official suite itself lives in an external immutable checkout and is run
 * by CI. These tests pin the adapter's own contract without a Python
 * dependency: strict one-request/one-response framing, envelope shape, exact
 * error-category mapping, real delegation to the production API, backend
 * self-checking, and fail-closed CSPRNG fault injection.
 */
final class OfficialAdapterTest extends TestCase
{
    private const ZERO_ID = '00000000000000000000000000';
    private const ZERO_BINARY = '0000000000000000000000000000000000';
    private const MAX_ID = 'zzzzzzz0000000000000000000';
    private const MAX_BINARY = '123ede3fff000000000000000000000000';

    public function testHelloAdvertisesOnlyRealCapabilities(): void
    {
        [$exit, $responses, $stderr] = $this->runAdapter([
            ['request_id' => 1, 'operation' => 'hello', 'arguments' => []],
        ]);

        self::assertSame(0, $exit, $stderr);
        self::assertCount(1, $responses);
        self::assertSame(
            ['generate_string', 'text_to_binary', 'binary_to_text'],
            array_values(array_intersect(
                $responses[0]['value']['capabilities'],
                ['generate_string', 'text_to_binary', 'binary_to_text']
            ))
        );
        self::assertSame(1, $responses[0]['value']['protocol_version']);
        $expectedCapabilities = [
            'binary_to_text',
            'boolean_validation',
            'csprng_failure_injection',
            'format_only_validation',
            'generate_binary',
            'generate_binary_with_timestamp',
            'generate_string',
            'generate_with_timestamp',
            'parse_components',
            'parse_datetime',
            'public_compare',
            'text_to_binary',
        ];
        $advertised = $responses[0]['value']['capabilities'];
        sort($expectedCapabilities);
        sort($advertised);
        self::assertSame($expectedCapabilities, $advertised);
    }

    public function testExactlyOneStrictEnvelopePerRequest(): void
    {
        [$exit, $responses, $stderr] = $this->runAdapter([
            ['request_id' => 7, 'operation' => 'boolean_validation', 'arguments' => ['text' => self::ZERO_ID]],
            ['request_id' => 8, 'operation' => 'format_only_validation', 'arguments' => ['text' => self::ZERO_ID]],
            ['request_id' => 9, 'operation' => 'public_compare', 'arguments' => ['a' => self::ZERO_ID, 'b' => self::MAX_ID]],
        ]);

        self::assertSame(0, $exit, $stderr);
        self::assertCount(3, $responses);
        self::assertSame([7, 8, 9], array_column($responses, 'request_id'));
        foreach ($responses as $response) {
            self::assertSame(['request_id', 'ok', 'value'], array_keys($response));
            self::assertTrue($response['ok']);
        }
        self::assertTrue($responses[0]['value']);
        self::assertTrue($responses[1]['value']);
        self::assertSame(-1, $responses[2]['value']);
    }

    public function testValidConversionsAndComponentsComeFromProductionApi(): void
    {
        [$exit, $responses, $stderr] = $this->runAdapter([
            ['request_id' => 1, 'operation' => 'text_to_binary', 'arguments' => ['text' => self::MAX_ID]],
            ['request_id' => 2, 'operation' => 'binary_to_text', 'arguments' => ['binary_hex' => self::MAX_BINARY]],
            ['request_id' => 3, 'operation' => 'parse_components', 'arguments' => ['text' => self::MAX_ID]],
            ['request_id' => 4, 'operation' => 'parse_components', 'arguments' => ['text' => self::ZERO_ID]],
        ]);

        self::assertSame(0, $exit, $stderr);
        self::assertSame(self::MAX_BINARY, $responses[0]['value']);
        self::assertSame(self::MAX_ID, $responses[1]['value']);
        self::assertSame(78364164095, $responses[2]['value']['timestamp_seconds']);
        self::assertSame('000000000000000000000000', $responses[2]['value']['randomness_hex']);
        self::assertSame('4453-04-05T15:21:35Z', $responses[2]['value']['datetime_utc']);
        self::assertSame(0, $responses[3]['value']['timestamp_seconds']);
        self::assertSame('1970-01-01T00:00:00Z', $responses[3]['value']['datetime_utc']);
    }

    public function testRejectionsMapToInvalidInputWithExactCategoryOnly(): void
    {
        [$exit, $responses, $stderr] = $this->runAdapter([
            ['request_id' => 1, 'operation' => 'text_to_binary', 'arguments' => ['text' => '0000000000000000000000000']],
            ['request_id' => 2, 'operation' => 'text_to_binary', 'arguments' => ['text' => '0000000000000000000000000Z']],
            ['request_id' => 3, 'operation' => 'text_to_binary', 'arguments' => ['text' => "000000000000\u{00e9}0000000000000"]],
            ['request_id' => 4, 'operation' => 'binary_to_text', 'arguments' => ['binary_hex' => substr(self::ZERO_BINARY, 2)]],
            ['request_id' => 5, 'operation' => 'binary_to_text', 'arguments' => ['binary_hex' => 'abc']],
            ['request_id' => 6, 'operation' => 'binary_to_text', 'arguments' => ['binary_hex' => 'ABCDEF' . substr(self::ZERO_BINARY, 6)]],
            ['request_id' => 7, 'operation' => 'binary_to_text', 'arguments' => ['binary_hex' => '123ede4000000000000000000000000000']],
            ['request_id' => 8, 'operation' => 'boolean_validation', 'arguments' => ['text' => self::ZERO_ID . ' ']],
        ]);

        self::assertSame(0, $exit, $stderr);
        self::assertCount(8, $responses);
        foreach (array_slice($responses, 0, 7) as $response) {
            self::assertSame(['request_id', 'ok', 'error'], array_keys($response));
            self::assertFalse($response['ok']);
            self::assertSame(['category', 'message'], array_keys($response['error']));
            self::assertSame('invalid_input', $response['error']['category']);
            self::assertNotSame('', $response['error']['message']);
        }
        self::assertFalse($responses[7]['value']);
    }

    public function testTimestampBoundsAreEnforcedThroughProductionApi(): void
    {
        [$exit, $responses, $stderr] = $this->runAdapter([
            ['request_id' => 1, 'operation' => 'generate_with_timestamp', 'arguments' => ['timestamp_seconds' => 78364164095]],
            ['request_id' => 2, 'operation' => 'generate_with_timestamp', 'arguments' => ['timestamp_seconds' => 78364164096]],
            ['request_id' => 3, 'operation' => 'generate_binary_with_timestamp', 'arguments' => ['timestamp_seconds' => -1]],
            ['request_id' => 4, 'operation' => 'generate_binary_with_timestamp', 'arguments' => ['timestamp_seconds' => 0]],
        ]);

        self::assertSame(0, $exit, $stderr);
        self::assertTrue($responses[0]['ok']);
        self::assertSame('zzzzzzz', substr($responses[0]['value'], 0, 7));
        self::assertSame('invalid_input', $responses[1]['error']['category']);
        self::assertSame('invalid_input', $responses[2]['error']['category']);
        self::assertTrue($responses[3]['ok']);
        self::assertSame(34, strlen($responses[3]['value']));
        self::assertSame('0000000000', substr($responses[3]['value'], 0, 10));
    }

    public function testCsprngFaultInjectionFailsClosedAndThenRecovers(): void
    {
        [$exit, $responses, $stderr] = $this->runAdapter([
            ['request_id' => 1, 'operation' => 'inject_fault', 'arguments' => ['fault' => 'csprng_failure', 'active' => true]],
            ['request_id' => 2, 'operation' => 'generate_string', 'arguments' => []],
            ['request_id' => 3, 'operation' => 'generate_binary_with_timestamp', 'arguments' => ['timestamp_seconds' => 0]],
            ['request_id' => 4, 'operation' => 'inject_fault', 'arguments' => ['fault' => 'csprng_failure', 'active' => false]],
            ['request_id' => 5, 'operation' => 'generate_string', 'arguments' => []],
        ]);

        self::assertSame(0, $exit, $stderr);
        self::assertTrue($responses[0]['ok']);
        self::assertSame('entropy_unavailable', $responses[1]['error']['category']);
        self::assertSame('entropy_unavailable', $responses[2]['error']['category']);
        self::assertTrue($responses[3]['ok']);
        self::assertTrue($responses[4]['ok']);
        self::assertSame(26, strlen($responses[4]['value']));
    }

    public function testUnknownOperationIsUnsupportedNotInvalidInput(): void
    {
        [$exit, $responses, $stderr] = $this->runAdapter([
            ['request_id' => 1, 'operation' => 'not_an_operation', 'arguments' => []],
            ['request_id' => 2, 'operation' => 'inject_fault', 'arguments' => ['fault' => 'other', 'active' => true]],
        ]);

        self::assertSame(0, $exit, $stderr);
        self::assertSame('unsupported_operation', $responses[0]['error']['category']);
        self::assertSame('invalid_input', $responses[1]['error']['category']);
    }

    public function testBackendExpectationMismatchRefusesToStart(): void
    {
        [$exit, $responses, $stderr] = $this->runAdapter(
            [['request_id' => 1, 'operation' => 'hello', 'arguments' => []]],
            ['PIKAID_EXPECT_BACKEND' => 'definitely-not-a-backend']
        );

        self::assertSame(2, $exit);
        self::assertSame([], $responses);
        self::assertStringContainsString('backend selection mismatch', $stderr);
    }

    public function testMalformedJsonIsAProtocolFailure(): void
    {
        [$exit, $responses, $stderr] = $this->runAdapter(['{"request_id":1,']);

        self::assertSame(2, $exit);
        self::assertSame([], $responses);
        self::assertStringContainsString('malformed JSON request', $stderr);
    }

    /**
     * @param list<array<string, mixed>|string> $requests
     * @param array<string, string>|null $environment
     * @return array{0: int, 1: list<array<string, mixed>>, 2: string}
     */
    private function runAdapter(array $requests, ?array $environment = null): array
    {
        $command = [PHP_BINARY, __DIR__ . '/official/adapter.php'];

        $env = null;
        if ($environment !== null) {
            $inherited = getenv();
            $env = is_array($inherited) ? $inherited : [];
            foreach ($environment as $name => $value) {
                $env[$name] = $value;
            }
        }

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($command, $descriptors, $pipes, dirname(__DIR__), $env);
        self::assertIsResource($process, 'adapter process');

        $stdin = '';
        foreach ($requests as $request) {
            $stdin .= is_string($request) ? $request : json_encode($request, JSON_THROW_ON_ERROR);
            $stdin .= "\n";
        }

        fwrite($pipes[0], $stdin);
        fclose($pipes[0]);

        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        $exit = proc_close($process);
        $stdout = $stdout === false ? '' : trim($stdout);
        $stderr = $stderr === false ? '' : $stderr;

        $responses = [];
        if ($stdout !== '') {
            foreach (explode("\n", $stdout) as $line) {
                $decoded = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
                self::assertIsArray($decoded, 'adapter emitted a non-object line');
                $responses[] = $decoded;
            }
        }

        return [$exit, $responses, $stderr];
    }
}
