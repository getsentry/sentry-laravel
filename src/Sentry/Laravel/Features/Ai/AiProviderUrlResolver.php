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
        'typesafe' => 'https://api.typesafe.ai/v1',
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

    /**
     * The host is used to identify classification calls that do not need the base URL since
     * they do not have chat spans or other HTTP related spans apart from the call itself.
     *
     * Another reason to just use the host is that internally, openrouter uses /v1/ for LLM calls
     * but does not for classification calls such as Jev, which uses /alpha. Laravel AI SDK handles this
     * by just removing the /v1/ so we would have to match the same logic for little benefit.
     *
     * @param object $provider
     */
    public static function host($provider): ?string
    {
        $baseUrl = self::baseUrl($provider);
        if ($baseUrl === null) {
            return null;
        }

        $host = parse_url($baseUrl, PHP_URL_HOST);

        return \is_string($host) ? strtolower($host) : null;
    }
}
