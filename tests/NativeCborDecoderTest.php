<?php

declare(strict_types=1);

namespace Matula\BlueskyCbor\Tests;

use Matula\BlueskyCbor\NativeCborDecoder;
use Matula\BlueskyCbor\Exceptions\CborDecodingException;
use PHPUnit\Framework\TestCase;

class NativeCborDecoderTest extends TestCase
{
    private NativeCborDecoder $decoder;

    protected function setUp(): void
    {
        $this->decoder = new NativeCborDecoder();
    }

    public function testDecodesSimpleInteger(): void
    {
        // CBOR: 0x01 (unsigned int 1)
        $data   = chr(1);
        $result = $this->decoder->decode($data, true);

        $this->assertEquals(['value_at_1' => 1], $result);
    }

    public function testDecodesNegativeInteger(): void
    {
        // CBOR: 0x20 (negative int -1)
        $data   = chr(0x20);
        $result = $this->decoder->decode($data);

        $this->assertEquals(['value_at_1' => -1], $result);
    }

    public function testDecodesString(): void
    {
        // CBOR: 0x63666F6F (string "foo")
        $data   = chr(0x63).'foo';
        $result = $this->decoder->decode($data);

        $this->assertEquals(['value_at_1' => 'foo'], $result);
    }

    public function testDecodesArray(): void
    {
        // CBOR: 0x82 0x01 0x02 (array [1, 2])
        $data   = chr(0x82).chr(0x01).chr(0x02);
        $result = $this->decoder->decode($data);

        $this->assertEquals(['array' => [1, 2]], $result);
    }

    public function testDecodesMap(): void
    {
        // CBOR: 0xA1 0x61 0x61 0x01 (map {"a": 1})
        $data   = chr(0xA1).chr(0x61).'a'.chr(0x01);
        $result = $this->decoder->decode($data);

        $this->assertEquals(['a' => 1], $result);
    }

    public function testDecodesCid(): void
    {
        // CBOR: Tagged value with tag 42 (CID)
        $data   = chr(0xD8).chr(0x2A).chr(0x44).'test';
        $result = $this->decoder->decode($data);

        $this->assertEquals(['cid' => bin2hex('test')], $result);
    }

    public function testDecodesSpecialValues(): void
    {
        // Test false (0xF4)
        $this->assertEquals(
            ['value_at_1' => false],
            $this->decoder->decode(chr(0xF4))
        );

        // Test true (0xF5)
        $this->assertEquals(
            ['value_at_1' => true],
            $this->decoder->decode(chr(0xF5))
        );

        // Test null (0xF6)
        $this->assertEquals(
            ['value_at_1' => null],
            $this->decoder->decode(chr(0xF6))
        );

        // Test undefined (0xF7)
        $this->assertEquals(
            ['value_at_1' => 'undefined'],
            $this->decoder->decode(chr(0xF7))
        );
    }

    public function testDecodesLargeIntegers(): void
    {
        // Test 8-bit unsigned integer (0x18 followed by one byte)
        $data   = chr(0x18).chr(0xFF);
        $result = $this->decoder->decode($data);
        $this->assertEquals(['value_at_1' => 255], $result);

        // Test 16-bit unsigned integer (0x19 followed by two bytes)
        $data   = chr(0x19).chr(0x01).chr(0x00);
        $result = $this->decoder->decode($data);
        $this->assertEquals(['value_at_1' => 256], $result);
    }

    public function testThrowsExceptionOnInvalidMajorType(): void
    {
        $this->markTestSkipped('try again later');

        $this->expectException(CborDecodingException::class);
        $this->expectExceptionMessage('Unknown major type: 8');

        // Create invalid CBOR data with major type 8 (doesn't exist)
        $data = chr(0b11100000);
        $this->decoder->decode($data);
    }

    public function testThrowsExceptionOnInvalidAdditionalInfo(): void
    {
        $this->expectException(CborDecodingException::class);
        $this->expectExceptionMessage('Invalid additional info for unsigned: 28');

        // Create invalid CBOR data with invalid additional info
        $data = chr(0x1C);  // Major type 0, additional info 28 (invalid)
        $this->decoder->decode($data);
    }

    public function testHandlesEmptyInput(): void
    {
        $result = $this->decoder->decode('');
        $this->assertEquals([], $result);
    }

    public function testHandlesComplexNestedStructures(): void
    {
        // Create a complex CBOR structure:
        // Map with array and nested map
        // {"a": [1, 2], "b": {"c": 3}}
        $data =
            chr(0xA2).                    // Map of 2 pairs
            chr(0x61).'a'.              // First key: "a"
            chr(0x82).                    // Array of 2 items
            chr(0x01).chr(0x02).        // Array contents: 1, 2
            chr(0x61).'b'.              // Second key: "b"
            chr(0xA1).                    // Nested map of 1 pair
            chr(0x61).'c'.              // Nested key: "c"
            chr(0x03);                     // Nested value: 3

        $result = $this->decoder->decode($data);

        $expected = [
            'a' => [1, 2],
            'b' => ['c' => 3],
        ];

        $this->assertEquals($expected, $result);
    }

    public function testHandlesDebugMode(): void
    {
        // This test just ensures debug mode doesn't break anything
        // In a real environment you might want to capture and verify the debug output
        $data   = chr(0x01);  // Simple integer 1
        $result = $this->decoder->decode($data, true);

        $this->assertEquals(['value_at_1' => 1], $result);
    }

    public function testHandlesMultipleValuesInStream(): void
    {
        // Skip
        $this->markTestSkipped('try again later');
        // Create CBOR data with multiple values:
        // 1 (integer), "foo" (string), true (special value)
        $data = chr(0x01).           // Integer 1
            chr(0x63).'foo'.    // String "foo"
            chr(0xF5);            // true

        $result = $this->decoder->decode($data);

        $expected = [
            'value_at_1' => 1,
            'value_at_2' => 'foo',
            'value_at_5' => true,
        ];

        $this->assertEquals($expected, $result);
    }
}