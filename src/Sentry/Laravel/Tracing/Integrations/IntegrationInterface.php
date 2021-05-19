<?php

namespace Sentry\Laravel\Tracing\Integrations;

interface IntegrationInterface
{
    public static function isApplicable(): bool;
}
