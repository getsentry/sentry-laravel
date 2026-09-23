<?php

namespace Sentry\Laravel\Tests\Features\Ai;

use Laravel\Ai\Responses\Data\TextUsage;
use Laravel\Ai\Responses\Data\Usage;
use PHPUnit\Framework\TestCase;
use Sentry\Laravel\Features\Ai\AiSpanDataBag;

class AiSpanDataBagTest extends TestCase
{
    protected function setUp(): void
    {
        if (!class_exists(Usage::class)) {
            $this->markTestSkipped('The laravel/ai package is not installed.');
        }
    }

    public function testTokenUsageIsRecorded(): void
    {
        // laravel/ai 1.0 moved the cache and reasoning counts onto `TextUsage` and swapped the
        // cache read and cache write arguments, so build the same usage for either version
        $usage = class_exists(TextUsage::class)
            ? new TextUsage(100, 50, 20, 5, 15)
            : new Usage(100, 50, 5, 20, 15);

        $data = new AiSpanDataBag();
        $data->setTokenUsage($usage);

        $this->assertSame(100, $data->get('gen_ai.usage.input_tokens'));
        $this->assertSame(50, $data->get('gen_ai.usage.output_tokens'));
        $this->assertSame(150, $data->get('gen_ai.usage.total_tokens'));
        $this->assertSame(20, $data->get('gen_ai.usage.input_tokens.cached'));
        $this->assertSame(5, $data->get('gen_ai.usage.input_tokens.cache_write'));
        $this->assertSame(15, $data->get('gen_ai.usage.output_tokens.reasoning'));
    }

    public function testTokenUsageWithoutCacheOrReasoningCountsIsRecorded(): void
    {
        // On laravel/ai 1.0 this is the plain `Usage` of embeddings, audio and reranking responses
        $data = new AiSpanDataBag();
        $data->setTokenUsage(new Usage(100, 50));

        $this->assertSame(100, $data->get('gen_ai.usage.input_tokens'));
        $this->assertSame(50, $data->get('gen_ai.usage.output_tokens'));
        $this->assertSame(150, $data->get('gen_ai.usage.total_tokens'));
        $this->assertNull($data->get('gen_ai.usage.input_tokens.cached'));
        $this->assertNull($data->get('gen_ai.usage.input_tokens.cache_write'));
        $this->assertNull($data->get('gen_ai.usage.output_tokens.reasoning'));
    }
}
