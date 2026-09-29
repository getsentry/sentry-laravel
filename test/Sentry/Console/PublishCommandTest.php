<?php

namespace Sentry\Laravel\Tests\Console;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Sentry\Laravel\Console\PublishCommand;

class PublishCommandTest extends TestCase
{
    public function testIsEnvKeySetTreatsRegexMetacharactersAsLiterals(): void
    {
        $command = new PublishCommand();

        $isEnvKeySetMethod = new ReflectionMethod($command, 'isEnvKeySet');
        if (\PHP_VERSION_ID < 80100) {
            $isEnvKeySetMethod->setAccessible(true);
        }

        $this->assertFalse((bool)$isEnvKeySetMethod->invoke(
            $command,
            'SENTRY.*KEY',
            "SENTRYAKEY=true\n"
        ));

        $this->assertTrue((bool)$isEnvKeySetMethod->invoke(
            $command,
            'SENTRY.*KEY',
            "SENTRY.*KEY=true\n"
        ));
    }

    public function testEnvKeyPatternEscapesRegexMetacharactersForReplacement(): void
    {
        $command = new PublishCommand();

        $getEnvKeyPatternMethod = new ReflectionMethod($command, 'getEnvKeyPattern');
        if (\PHP_VERSION_ID < 80100) {
            $getEnvKeyPatternMethod->setAccessible(true);
        }

        $pattern = $getEnvKeyPatternMethod->invoke($command, 'SENTRY.*KEY');

        $updatedContents = preg_replace(
            $pattern,
            "SENTRY.*KEY=new\n",
            "SENTRYAKEY=old\nSENTRY.*KEY=old\n"
        );

        $this->assertSame("SENTRYAKEY=old\nSENTRY.*KEY=new\n", $updatedContents);
    }
}
