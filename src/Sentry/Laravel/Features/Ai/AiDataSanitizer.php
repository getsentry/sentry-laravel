<?php

namespace Sentry\Laravel\Features\Ai;

/**
 * @internal
 */
class AiDataSanitizer
{
    /** Maximum total byte size for serialized message data. */
    public const MAX_MESSAGE_BYTES = 20000;

    /** Maximum character length for a single message's content string. */
    public const MAX_SINGLE_MESSAGE_CONTENT_CHARS = 10000;

    /** Placeholder for binary content that should not be sent to Sentry. */
    public const BLOB_SUBSTITUTE = '[Blob substitute]';

    /** Regex pattern to detect data URIs (e.g. data:image/png;base64,...). */
    private const DATA_URI_PATTERN = '/^data:([^;,]+)?(?:;([^,]*))?,/s';

    /** Regex pattern to detect standalone base64-encoded strings (100+ chars). */
    private const BASE64_PATTERN = '/^[A-Za-z0-9+\/]{100,}={0,2}$/';

    public static function truncateString(?string $value, int $maxBytes = self::MAX_MESSAGE_BYTES): ?string
    {
        if ($value === null) {
            return null;
        }

        if (\strlen($value) <= $maxBytes) {
            return $value;
        }

        return substr($value, 0, $maxBytes) . '...(truncated)';
    }

    public static function truncateContentString(string $value): string
    {
        if (mb_strlen($value) <= self::MAX_SINGLE_MESSAGE_CONTENT_CHARS) {
            return $value;
        }

        return mb_substr($value, 0, self::MAX_SINGLE_MESSAGE_CONTENT_CHARS) . '...';
    }

    public static function redactBinaryInString(string $value): string
    {
        if (self::isBinaryString($value)) {
            return self::BLOB_SUBSTITUTE;
        }

        return $value;
    }

    /**
     * Encodes arbitrary values using `json_encode` unless they are strings already, in which
     * case the same string is returned.
     * If `json_encode` fails, it will return null. The reason for that is that we don't distinguish
     * a lot here between null and false, both mean that we do not want to include them as facts.
     *
     * @param mixed|null $data
     */
    public static function encodeIfNotString($data = null): ?string
    {
        if ($data === null) {
            return null;
        }
        if (\is_string($data)) {
            return $data;
        }
        $encoded = json_encode($data);

        return $encoded !== false ? $encoded : null;
    }

    private static function isBinaryString(string $value): bool
    {
        return self::isDataUri($value) || self::isBase64String($value);
    }

    private static function isDataUri(string $value): bool
    {
        return (bool) preg_match(self::DATA_URI_PATTERN, $value);
    }

    private static function isBase64String(string $value): bool
    {
        return (bool) preg_match(self::BASE64_PATTERN, $value);
    }
}
