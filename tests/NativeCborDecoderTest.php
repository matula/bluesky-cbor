<?php

namespace Matula\BlueskyCbor\Tests;

use Matula\BlueskyCbor\NativeCborDecoder;
use PHPUnit\Framework\TestCase;

class NativeCborDecoderTest extends TestCase
{
    private NativeCborDecoder $decoder;

    protected function setUp(): void
    {
        parent::setUp();
        $this->decoder = new NativeCborDecoder();
    }

    public function testDecodesUnsignedIntegers(): void
    {
        // Test 1-byte integer (24-255)
        $data = chr(24) . chr(100);  // 100
        $result = $this->decoder->decode($data);
        $this->assertEquals(['value_at_1' => 100], $result);

        // Test 2-byte integer
        $data = chr(25) . chr(1) . chr(0);  // 256
        $result = $this->decoder->decode($data);
        $this->assertEquals(['value_at_1' => 256], $result);

        // Test 4-byte integer
        $data = chr(26) . chr(0) . chr(1) . chr(0) . chr(0);  // 65536
        $result = $this->decoder->decode($data);
        $this->assertEquals(['value_at_1' => 65536], $result);
    }

    public function testDecodesNegativeIntegers(): void
    {
        // Test negative integer with additional bytes
        $data = chr(56) . chr(100);  // -101
        $result = $this->decoder->decode($data);
        $this->assertEquals(['value_at_1' => -101], $result);
    }

    public function testDecodesStrings(): void
    {
        // Test short string
        $data = chr(97) . 'a';  // "a"
        $result = $this->decoder->decode($data);
        $this->assertEquals(['value_at_1' => 'a'], $result);

        // Test longer string
        $str = 'Hello, World!';
        $data = chr(109) . $str;  // "Hello, World!"
        $result = $this->decoder->decode($data);
        $this->assertEquals(['value_at_1' => $str], $result);
    }

    public function testDecodesArrays(): void
    {
        // Test simple array
        $data = chr(131) . chr(1) . chr(2) . chr(3);  // [1, 2, 3]
        $result = $this->decoder->decode($data);
        $this->assertEquals(['array' => [1, 2, 3]], $result);

        // Test nested array
        $data = chr(130) . chr(1) . chr(130) . chr(2) . chr(3);  // [1, [2, 3]]
        $result = $this->decoder->decode($data);
        $this->assertEquals(['array' => [1, ['array' => [2, 3]]]], $result);
    }

    public function testDecodesMaps(): void
    {
        // Test empty map
        $data = chr(160);  // {}
        $result = $this->decoder->decode($data);
        $this->assertEquals([], $result);

        // Test simple map
        $data = chr(161) . chr(97) . 'a' . chr(1);  // {"a": 1}
        $result = $this->decoder->decode($data);
        $this->assertEquals(['a' => 1], $result);

        // Test nested map
        $data = chr(161) . chr(97) . 'a' . chr(161) . chr(97) . 'b' . chr(2);  // {"a": {"b": 2}}
        $result = $this->decoder->decode($data);
        $this->assertEquals(['a' => ['b' => 2]], $result);
    }

    public function testDecodesTags(): void
    {
        // Test CID tag (42)
        $bytes = pack('C*', 0xD8, 0x2A, 0x44, 0x01, 0x02, 0x03, 0x04);  // Tag 42 with 4 bytes
        $result = $this->decoder->decode($bytes);
        $this->assertEquals(['cid' => '01020304'], $result);
    }

    public function testHandlesInvalidInput(): void
    {
        // Test empty input
        $result = $this->decoder->decode('');
        $this->assertEquals([], $result);

        // Test invalid major type
        $data = chr(224);  // Major type 7 with invalid additional info
        $result = $this->decoder->decode($data);
        $this->assertEquals([], $result);  // Should not throw exception but return empty array

        // Test truncated input
        $data = chr(25);  // 2-byte integer without the bytes
        $result = $this->decoder->decode($data);
        $this->assertEquals([], $result);
    }
}