<?php

namespace Sentry\Laravel\Tests\Features\Ai;

use PHPUnit\Framework\TestCase;
use Sentry\Laravel\Features\Ai\AiDataSanitizer;

class AiDataSanitizerTest extends TestCase
{
    public function testTruncateStringKeepsValuesWithinTheByteLimit(): void
    {
        $this->assertNull(AiDataSanitizer::truncateString(null));
        $this->assertSame('short', AiDataSanitizer::truncateString('short'));
        $this->assertSame(
            str_repeat('a', AiDataSanitizer::MAX_MESSAGE_BYTES),
            AiDataSanitizer::truncateString(str_repeat('a', AiDataSanitizer::MAX_MESSAGE_BYTES))
        );
    }

    public function testTruncateStringCutsValuesOverTheByteLimit(): void
    {
        $this->assertSame(
            str_repeat('a', AiDataSanitizer::MAX_MESSAGE_BYTES) . '...(truncated)',
            AiDataSanitizer::truncateString(str_repeat('a', AiDataSanitizer::MAX_MESSAGE_BYTES + 1))
        );
        $this->assertSame('abc...(truncated)', AiDataSanitizer::truncateString('abcdef', 3));
    }

    public function testTruncateContentStringCountsCharactersNotBytes(): void
    {
        $withinLimit = str_repeat('ü', AiDataSanitizer::MAX_SINGLE_MESSAGE_CONTENT_CHARS);
        $this->assertSame($withinLimit, AiDataSanitizer::truncateContentString($withinLimit));

        $this->assertSame(
            $withinLimit . '...',
            AiDataSanitizer::truncateContentString($withinLimit . 'ü')
        );
    }

    public function testRedactBinaryInStringReplacesDataUrisAndBase64(): void
    {
        $this->assertSame(
            AiDataSanitizer::BLOB_SUBSTITUTE,
            AiDataSanitizer::redactBinaryInString('data:image/png;base64,iVBORw0KGgo=')
        );
        $this->assertSame(
            AiDataSanitizer::BLOB_SUBSTITUTE,
            AiDataSanitizer::redactBinaryInString(str_repeat('QUJD', 25))
        );
    }

    public function testRedactBinaryInStringKeepsRegularText(): void
    {
        $this->assertSame('What is the weather in Paris?', AiDataSanitizer::redactBinaryInString('What is the weather in Paris?'));
        // Base64 alphabet, but shorter than the 100 character threshold
        $this->assertSame('QUJD', AiDataSanitizer::redactBinaryInString('QUJD'));
    }

    public function testEncodeIfNotString(): void
    {
        $this->assertNull(AiDataSanitizer::encodeIfNotString(null));
        $this->assertSame('already a string', AiDataSanitizer::encodeIfNotString('already a string'));
        $this->assertSame('{"city":"Paris"}', AiDataSanitizer::encodeIfNotString(['city' => 'Paris']));
        $this->assertSame('42', AiDataSanitizer::encodeIfNotString(42));
        // Invalid UTF-8 cannot be encoded
        $this->assertNull(AiDataSanitizer::encodeIfNotString(["\xB1\x31"]));
    }
}
