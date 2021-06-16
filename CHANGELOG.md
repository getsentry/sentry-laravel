# Changelog

## Unreleased

## 2.7.0

- Replace type hint of concrete type (`Sentry\State\Hub`) with interface (`Sentry\State\HubInterface`) in `SentryHandler` constructor (#496)
- Use latest version of the Sentry PHP SDK (#499)

## 2.6.0

- Add all log context as `log_context` to events when using the log channel (#489)
- Add integration to improve performance tracing for [Laravel Lighthouse](https://github.com/nuwave/lighthouse) (#490)

## 2.5.3

- Correctly call flush on the PHP SDK client (#484)
- Fix errors on Laravel `5.x` caused by Laravel not using `nyholm/psr7` to generate PSR-7 request but older `zendframework/zend-diactoros` package which might not be available

## 2.5.2

- Fix problem with parsing uploaded files from request after they have been moved (#487)

## 2.5.1

- Fix problem with queue tracing when triggered from unit tests or when missing a queue name in the event

## 2.5.0

- Add `sql.origin` to SQL query spans with the file and line where the SQL query originated from (#398)
- Remove wrapper around the context of log entry breadcrumbs (#405)
- Ensure user integrations are always executed after SDK integrations (#474)
- Fix repeated booted callback registration from performance tracing middleware (#475)
- Add tracing support for queue jobs, enable with `SENTRY_TRACE_QUEUE_ENABLED=true` (#478)
- Add options to disable parts of performance tracing (#478)
- Remove string representation of exception from exceptions logged through log channels (#482)
- Use message from Monolog record to prevent bloating the log message being recorded with timestamps and log log level (#482)
- Add `report_exceptions` option to the Sentry log channel that can be set to `false` to not report exceptions (#482)

## 2.4.2

- Avoid collision if another package has bound `sentry` in the Laravel container (#467)

## 2.4.1

- Fix type hints incompatible with Laravel Lumen (#462)

## 2.4.0

- Read the request IP from the Laravel request to make it more accurate when behind a reverse proxy (requires [trusted proxies](https://laravel.com/docs/8.x/requests#configuring-trusted-proxies) to be setup correctly) (#419)
- Get request information (like the URL) from the Laravel request instead of constructing it from the global state (#419)
- Fix generated route name not correctly ignored when using prefix (#441)
- Fix overwriting the transaction name if it's set by the user (#442)
- Add result from optional `context(): array` method on captured exception to the event sent to Sentry (#457)
- Fix not overwriting the event transaction name if it was an empty string (#460)
- Bump Sentry SDK to `3.2.*`

## 2.3.1

- Fix problems when enabling tracing on Laravel Lumen (#416)
- PHP 8 Support (#431)

## 2.3.0

- Bump Sentry SDK to `3.1.*` (#420)

## 2.2.0

- Fix incorrectly stripped base controller action from transaction name (#406)
- Move tracing request/response data hydration to the tracing middleware (#408)

## 2.1.1

- Fix for potential `Undefined index: controllers_base_namespace.` notice

## 2.1.0

- Added a option (`controllers_base_namespace`) to strip away the controller base namespace for cleaner transaction names (#393)
- Fix incompatibility with other packages that also decorate the view engine, like Livewire (#395)

## 2.0.1

- Improve performance tracing by nesting `view.render` spans and adding a `app.handle` span showing how long the actual application code runs after Laravel bootstrapping (#387)
- Improve UX of `sentry:publish` command

## 2.0.0

**Breaking Change**: This version uses the [envelope endpoint](https://develop.sentry.dev/sdk/envelopes/). If you are
using an on-premise installation it requires Sentry version `>= v20.6.0` to work. If you are using
[sentry.io](https://sentry.io) nothing will change and no action is needed.

**Tracing API / Monitor Performance**

In this version we released API for Tracing. `\Sentry\startTransaction` is your entry point for manual instrumentation.
More information can be found in our [Performance](https://docs.sentry.io/platforms/php/guides/laravel/performance/) docs.

- Using `^3.0` of Sentry PHP SDK
- Add support for Tracing, enable it by setting `traces_sample_rate` in the config to a value > 0 (the value should be larger than `0.0` and smaller or equal than `1.0` (to send everything))

## 2.0.0-beta1

**Breaking Change**: This version uses the [envelope endpoint](https://develop.sentry.dev/sdk/envelopes/). If you are
using an on-premise installation it requires Sentry version `>= v20.6.0` to work. If you are using
[sentry.io](https://sentry.io) nothing will change and no action is needed.

- Using `3.0.0-beta1` of Sentry PHP SDK
- Add support for Tracing, enable it by setting `traces_sample_rate` in the config to a value > 0 (the value should be larger than `0.0` and smaller or equal than `1.0` (to send everything))

## 1.9.0

- Respect the `SENTRY_ENVIRONMENT` environment variable to override the Laravel environment (#354)
- Support for Laravel 8 (#374)

## 1.8.0

- Add `send_default_pii` option by default to published config file (#340)
- Update `.gitattributes` to exclude more files from dist release (#341)
- Ignore log breadcrumbs when `null` is the message logged (#345)
- Fix `breadcrumbs.queue_info` controlling breadcrumbs generated by commands (#350)
- Add `breadcrumbs.command_info` to control breadcrumbs generated by commands (#350)
- Fixed scope data in queue jobs being lost in some cases (#351)

## 1.7.1

- Discard Laravel 7 route cache generated route names (#337)

## 1.7.0

- Support for Laravel 7 (#330)

## 1.6.2

- Fix for default integrations not disabled (#327)

## 1.6.1

- Fix queue events with missing handler suffix (#322)

## 1.6.0

- Use default breadcrumb type for handled events (#303)
- Support Sentry SDK ^2.3 (and drop support for older versions) (#316)
- Fix queue events to correctly flush events when not running a queue deamon (#318)

## 1.5.0

- Fix throwing errors when installed when config cache is active (6214338)
- Allow any log level to create breadcrumbs (#297)
- Allow decorating the `ClientBuilderInterface` from the `register` method of a Service Provider (#290)

## 1.4.1

- Fix default Monolog logger level being invalid when using the Log channel (#287)

## 1.4.0

- Add the query execution time to the query breadcrumb (#283)
- Do not register default error and fatal listeners to prevent duplicated events (#280)

## 1.3.1

- Fix compatibility with sentry/sentry 2.2+ (#276)

## 1.3.0

- Add compatibility with sentry/sentry 2.2+ (#273)

## 1.2.1

- Fix fatal error when user context is not an array when using log channels (#272)

## 1.2.0

- Support for Laravel 6 (#269)

## 1.1.1

- Fix custom container alias (#263)

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
