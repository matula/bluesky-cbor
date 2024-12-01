<?php

declare(strict_types=1);

namespace Matula\BlueskyCbor\Contracts;

interface CborDecoderInterface
{
    /**
     * Decode CBOR data into PHP array
     *
     * @param string $data Raw CBOR data
     * @param bool $debug Enable debug output
     * @return array Decoded data
     */
    public function decode(string $data, bool $debug = false): array;
}