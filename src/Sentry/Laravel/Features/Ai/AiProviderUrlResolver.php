<?php

namespace Sentry\Laravel\Features\Ai;

use Laravel\Ai\Providers\Provider;

/**
 * @internal
 */
class AiProviderUrlResolver
{
    /** Default base URLs for known AI provider drivers, used when no URL is configured. */
    private const KNOWN_PROVIDER_URLS = [
        'anthropic' => 'https://api.anthropic.com/v1',
        'deepseek' => 'https://api.deepseek.com/v1',
        'gemini' => 'https://generativelanguage.googleapis.com/v1beta/',
        'groq' => 'https://api.groq.com/openai/v1',
        'mistral' => 'https://api.mistral.ai/v1',
        'ollama' => 'http://localhost:11434',
        'openai' => 'https://api.openai.com/v1',
        'openrouter' => 'https://openrouter.ai/api/v1',
        'voyageai' => 'https://api.voyageai.com/v1',
        'xai' => 'https://api.x.ai/v1',
    ];

    /**
     * @param object $provider
     */
    public static function baseUrl($provider): ?string
    {
        if (!$provider instanceof Provider) {
            return null;
        }

        $url = $provider->additionalConfiguration()['url']
            ?? config("prism.providers.{$provider->driver()}.url")
            ?? self::KNOWN_PROVIDER_URLS[$provider->driver()] ?? null;

        return \is_string($url) && $url !== '' ? $url : null;
    }
}
