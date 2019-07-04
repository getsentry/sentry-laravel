# Changelog

## 1.1.0

- Register alias `HubInterface` to container (#249)
- Resolve `integrations` option from the container (#239)

## 1.0.2

- Track Artisan command invocation in breadcrumb (#232)
- Fixed `sql_bindings` configuration fallback (#231)
- Fixed events generated in queue worker not sending until worker exits (#228)
- Add phpDoc methods to the facade for better autocompletion (#226)
- Fallback to `SENTRY_DSN` if defined in env (#224)

## 1.0.1

- Fix the configuration syntax for the sql bindings in breadcrumbs configuration option to be compatible with Laravel (#207)
- Prevent registering events when no DSN is set (#205)

## 1.0.0

- This version requires `sentry/sentry` `>= 2.0` and also PHP `>= 7.1`
- Support for Laravel 5.8
- Be advised `app('sentry')` now no longer returns the "old" `Raven_Client` instead it will return `\Sentry\State\Hub`

Please see [Docs](https://docs.sentry.io/platforms/php/laravel/) for detailed usage.

## 0.11.0

- Correctly merge the user config with the default configuration file (#163)
- Listen for queue events and flush the send queue and breadcrum queue (#153)
- Add tag with the console command name to the event (#146)

## 0.10.1

- Fix support for Laravel 5.0.

## 0.10.0

- Support for Laravel 5.7.

## 0.9.2

- The `sentry:test` artisan command no longer requires the secret key in the DSN (secret key in DSN deprecated since Sentry 9).

## 0.9.1

- Allow setting custom formatter for the log channel. (#145)

## 0.9.0

This version no longer supports Laravel 4.x, version `0.8.x` will of course still work for Laravel 4.

- Set 'user_context' configuration default to false. (#132)
- Update `SENTRY_DSN` env variable name to `SENTRY_LARAVEL_DSN`. (#130)
- Improved default app_path for Lumen to include entire application code, excluding vendor. (#128)
- Remove Laravel 4 support. (#123)
- Add support for Laravel 5.6 log channels. (#122)
- Type hint Laravel contracts instead of implementation. (#107)

## 0.8.0

- Improved default app_path to include entire application code, excluding vendor. (#89)
- Fix for auth context not working properly on Laravel >=5.3. (#81)
- Support Laravel auto-discovery. (#78)

## 0.7.0

- Added 'sentry:test' to Artisan. (#65)
- Added 'user_context' configuration to disable automatic collection. (#55)

## 0.6.1

- Various fixes for query event breadcrumbs. (#54)

## 0.6.0

- Support for Laravel 5.4.

## 0.5.0

- Require sentry/sentry >= 1.6.0.
- Allow overriding abstract type Sentry is bound to in service container.

## 0.4.0

- Require sentry/sentry >= 1.5.0.
- Added support for Illuminate SQL queries in breadcrumbs.
- Replaced Monolog breadcrumb handler with Illuminate log handler.
- Added route transaction names.
