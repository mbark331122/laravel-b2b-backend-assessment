<?php

namespace App\Services;

class MockAiExtractor
{
    public const SOURCE = 'AI/mock';

    /**
     * Parse RFQ text into proposed fields. This class never receives or updates an RFQ.
     *
     * @return array{
     *     commodity: string,
     *     specification: string,
     *     quantity: int,
     *     unit: string,
     *     incoterm: string,
     *     destination: string,
     *     confidence: float,
     *     source: string
     * }|null
     */
    public function extract(string $text): ?array
    {
        $normalized = preg_replace('/(\d),(\d)/', '$1$2', $text) ?? $text;

        $matched = preg_match(
            '/(\d+)\s+(MT|KG|LB)\s+(ICUMSA\s+\d+)\s+([A-Za-z]+),\s+(CIF|FOB|CFR|EXW)\s+([A-Za-z]+)/i',
            $normalized,
            $matches
        );

        if (! $matched) {
            return null;
        }

        return [
            'commodity' => ucfirst(strtolower($matches[4])),
            'specification' => strtoupper($matches[3]),
            'quantity' => (int) $matches[1],
            'unit' => strtoupper($matches[2]),
            'incoterm' => strtoupper($matches[5]),
            'destination' => ucfirst(strtolower($matches[6])),
            'confidence' => 0.9,
            'source' => self::SOURCE,
        ];
    }
}
