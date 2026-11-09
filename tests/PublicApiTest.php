<?php

declare(strict_types=1);

use Pikaid\Pikaid;
use Pikaid\PikaidException;
use PHPUnit\Framework\TestCase;

/**
 * Locks the public API surface of the PHP SDK.
 *
 * The capability list mirrors the cross-SDK feature matrix documented in
 * README.md. It makes accidental removals or signature changes visible in CI
 * even when no behavioral test happens to call the affected method.
 */
final class PublicApiTest extends TestCase
{
    /**
     * Capability => PHP entrypoints. Every pikaid SDK exposes these abilities
     * (under its own naming conventions); the PHP names are frozen here.
     */
    private const CAPABILITIES = [
        'generate-string' => ['generate', 'generateWithTimestamp'],
        'generate-binary' => ['generateBinary', 'generateBinaryWithTimestamp'],
        'generate-from-datetime' => ['fromDateTime'],
        'validate' => ['isValid', 'isWellFormed', 'validateOrThrow'],
        'convert' => ['toBinary', 'fromBinary'],
        'extract' => ['decode', 'getTimestamp', 'getEntropyBytes', 'getEntropyHex', 'parse'],
        'compare' => ['compare'],
    ];

    public function testPublicMethodSurfaceIsFrozen(): void
    {
        $expected = [
            'generate' => ['', 'string'],
            'generateWithTimestamp' => ['int $timestamp', 'string'],
            'generateBinary' => ['', 'string'],
            'generateBinaryWithTimestamp' => ['int $timestamp', 'string'],
            'validateOrThrow' => ['string $id', 'array'],
            'isValid' => ['string $id', 'bool'],
            'isWellFormed' => ['string $id', 'bool'],
            'toBinary' => ['string $id', 'string'],
            'fromBinary' => ['string $binary', 'string'],
            'decode' => ['string $id', 'array'],
            'getTimestamp' => ['string $id', 'int'],
            'getEntropyBytes' => ['string $id', 'string'],
            'getEntropyHex' => ['string $id', 'string'],
            'parse' => ['string $id', 'array'],
            'fromDateTime' => ['DateTimeInterface $dateTime', 'string'],
            'compare' => ['string $a, string $b', 'int'],
        ];

        $actual = [];
        $reflection = new ReflectionClass(Pikaid::class);
        foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->getDeclaringClass()->getName() !== Pikaid::class) {
                continue;
            }

            $parameters = [];
            foreach ($method->getParameters() as $parameter) {
                $type = $parameter->getType();
                $parameters[] = ($type === null ? '' : (string) $type) . ' $' . $parameter->getName();
            }

            $returnType = $method->getReturnType();
            $actual[$method->getName()] = [implode(', ', $parameters), $returnType === null ? '' : (string) $returnType];
        }

        ksort($actual);
        ksort($expected);

        self::assertSame($expected, $actual, 'Public method signatures changed; this is a breaking change.');
        self::assertTrue($reflection->isFinal(), 'Pikaid must remain final to keep the static API stable.');
    }

    public function testEveryCapabilityHasAPublicEntrypoint(): void
    {
        $reflection = new ReflectionClass(Pikaid::class);

        foreach (self::CAPABILITIES as $capability => $methods) {
            self::assertNotSame([], $methods, $capability);
            foreach ($methods as $method) {
                self::assertTrue($reflection->hasMethod($method), $capability . ' is missing ' . $method);
                self::assertTrue($reflection->getMethod($method)->isPublic(), $capability . '/' . $method . ' is not public');
                self::assertTrue($reflection->getMethod($method)->isStatic(), $capability . '/' . $method . ' is not static');
            }
        }
    }

    public function testPublicConstantsAndErrorCodesAreFrozen(): void
    {
        self::assertSame(26, Pikaid::LENGTH);
        self::assertSame(17, Pikaid::BINARY_LENGTH);
        self::assertSame(78364164095, Pikaid::MAX_TIMESTAMP);

        $codes = [
            'CODE_INVALID_LENGTH' => 1,
            'CODE_INVALID_CHARACTER' => 2,
            'CODE_TIMESTAMP_OVERFLOW' => 3,
            'CODE_TIMESTAMP_NEGATIVE' => 4,
            'CODE_ENTROPY_OVERFLOW' => 5,
            'CODE_INVALID_BINARY_LENGTH' => 6,
            'CODE_INVALID_BINARY_TYPE' => 7,
        ];

        $reflection = new ReflectionClass(PikaidException::class);
        foreach ($codes as $constant => $value) {
            self::assertTrue($reflection->hasConstant($constant), 'missing exception constant ' . $constant);
            self::assertSame($value, constant(PikaidException::class . '::' . $constant));
        }

        self::assertSame(count($codes), count(array_unique($codes)), 'error codes must be unique');
    }

    public function testExceptionFactoriesRemainAvailable(): void
    {
        $expected = [
            'invalidLength' => [PikaidException::CODE_INVALID_LENGTH, [25, 26]],
            'invalidCharacter' => [PikaidException::CODE_INVALID_CHARACTER, ['A', 0]],
            'timestampOverflow' => [PikaidException::CODE_TIMESTAMP_OVERFLOW, [Pikaid::MAX_TIMESTAMP + 1, Pikaid::MAX_TIMESTAMP]],
            'timestampNegative' => [PikaidException::CODE_TIMESTAMP_NEGATIVE, [-1]],
            'entropyOverflow' => [PikaidException::CODE_ENTROPY_OVERFLOW, [13, 12]],
            'invalidBinaryLength' => [PikaidException::CODE_INVALID_BINARY_LENGTH, [16, 17]],
            'invalidBinaryType' => [PikaidException::CODE_INVALID_BINARY_TYPE, ['int']],
        ];

        $reflection = new ReflectionClass(PikaidException::class);
        foreach ($expected as $factory => [$code, $arguments]) {
            self::assertTrue($reflection->hasMethod($factory), 'missing exception factory ' . $factory);
            $exception = call_user_func_array([PikaidException::class, $factory], $arguments);
            self::assertInstanceOf(PikaidException::class, $exception, $factory);
            self::assertSame($code, $exception->getCode(), $factory);
            self::assertNotSame('', $exception->getMessage(), $factory);
        }
    }
}
