<?php

namespace Tests\Unit;

use App\Services\MockAiExtractor;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class MockAiExtractorTest extends TestCase
{
    public function test_it_extracts_the_required_assessment_text(): void
    {
        $result = (new MockAiExtractor)->extract('Need 25,000 MT ICUMSA 45 Sugar, CIF Jeddah.');

        $this->assertNotNull($result);
        $this->assertSame('Sugar', $result['commodity']);
        $this->assertSame('ICUMSA 45', $result['specification']);
        $this->assertSame(25000, $result['quantity']);
        $this->assertSame('MT', $result['unit']);
        $this->assertSame('CIF', $result['incoterm']);
        $this->assertSame('Jeddah', $result['destination']);
        $this->assertSame(0.9, $result['confidence']);
        $this->assertSame(MockAiExtractor::SOURCE, $result['source']);
    }

    public function test_extractor_only_accepts_text(): void
    {
        $method = new ReflectionMethod(MockAiExtractor::class, 'extract');
        $parameters = $method->getParameters();

        $this->assertCount(1, $parameters);
        $this->assertSame('text', $parameters[0]->getName());
        $this->assertSame('string', (string) $parameters[0]->getType());
    }
}
