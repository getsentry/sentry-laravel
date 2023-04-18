# Changelog

## 3.3.3

The Sentry SDK team is happy to announce the immediate availability of Sentry Laravel SDK v3.3.3.

### Bug Fixes

- Fix `CheckIn` constructor argument order [(#680)](https://github.com/getsentry/sentry-laravel/pull/680)
- Fix missing breadcrumbs for jobs throwing an exception [(#633)](https://github.com/getsentry/sentry-laravel/pull/633)

## 3.3.2

The Sentry SDK team is happy to announce the immediate availability of Sentry Laravel SDK v3.3.2.

### Bug Fixes

- Fix "Object of class Closure could not be converted to string" error when tracing `redis_commands` [(#668)](https://github.com/getsentry/sentry-laravel/pull/668)

## 3.3.1

The Sentry SDK team is happy to announce the immediate availability of Sentry Laravel SDK v3.3.1.

### Bug Fixes

-  Fix scheduled commands running in the background not reporting success/failure [(#664)](https://github.com/getsentry/sentry-laravel/pull/664)

## 3.3.0

The Sentry SDK team is happy to announce the immediate availability of Sentry Laravel SDK v3.3.0.
This release adds initial support for [Cron Monitoring](https://docs.sentry.io/product/crons/) as well as new performance spans and breadcrumbs.

> **Warning**
> Cron Monitoring is currently in beta. Beta features are still in-progress and may have bugs. We recognize the irony.
> If you have any questions or feedback, please email us at crons-feedback@sentry.io, reach out via Discord (#cronjobs), or open an issue.

### Features

- Add inital support for Cron Monitoring [(#659)](https://github.com/getsentry/sentry-laravel/pull/659)

  After creating your Cron Monitor on https://sentry.io, you can add the `sentryMonitor()` macro to your scheduled tasks defined in your `app/Console/Kernel.php` file.
  This will let Sentry know if your scheduled task started, whether the task was successful or failed, and its duration.

  ```php
  protected function schedule(Schedule $schedule)
  {
      $schedule->command('emails:send')
          ->everyHour()
          ->sentryMonitor('<your-monitor-slug>'); // add this line
  }
  ```

- Add Livewire tracing integration [(#657)](https://github.com/getsentry/sentry-laravel/pull/657)

  You can enable this feature by adding new config options to your `config/sentry.php` file.

  ```php
  'breadcrumbs' => [
      // Capture Livewire components in breadcrumbs
      'livewire' => true,
  ],
  'tracing' => [
      // Capture Livewire components as spans
      'livewire' => true,
  ],
  ```

- Add Redis operation spans & cache event breadcrumbs [(#656)](https://github.com/getsentry/sentry-laravel/pull/656)

  You can enable this feature by adding new config options to your `config/sentry.php` file.

  ```php
  'breadcrumbs' => [
      // Capture Laravel cache events in breadcrumbs
      'cache' => true,
  ],
  'tracing' => [
      // Capture Redis operations as spans (this enables Redis events in Laravel)
      'redis_commands' => env('SENTRY_TRACE_REDIS_COMMANDS', false),

      // Try to find out where the Redis command originated from and add it to the command spans
      'redis_origin' => true,
  ],

- Add HTTP client request breadcrumbs [(#640)](https://github.com/getsentry/sentry-laravel/pull/640)

  You can enable this feature by adding a new config option to your `config/sentry.php` file.

  ```php
  'breadcrumbs' => [
      // Capture HTTP client requests information in breadcrumbs
      'http_client_requests' => true,
  ],

- Offer the installation of a JavaScript SDK when running `sentry:publish` [(#647)](https://github.com/getsentry/sentry-laravel/pull/647)

### Bug Fixes

- Fix a log channel context crash when unexpected values are passed [(#651)](https://github.com/getsentry/sentry-laravel/pull/651)

### Misc

- The SDK is now licensed under MIT [(#654)](https://github.com/getsentry/sentry-php/pull/654)
  - Read more about Sentry's licensing [here](https://open.sentry.io/licensing/).

## 3.2.0

The Sentry SDK team is happy to announce the immediate availability of Sentry Laravel SDK v3.2.0.
This release adds support for Laravel 10.

### Features

- Add support for Laravel 10 [(#630)](https://github.com/getsentry/sentry-laravel/pull/630)
    - Thanks to [@jnoordsij](https://github.com/jnoordsij) for their contribution.
- Add `tracing.http_client_requests` option [(#641)](https://github.com/getsentry/sentry-laravel/pull/641)
    - You can now disable HTTP client tracing in your `config/sentry.php` file

      ```php
      'tracing' => [
          'http_client_requests' => true|false, // This feature is enabled by default
      ],
      ```

## 3.1.3

- Increase debug trace limit count to 20 in `Integration::makeAnEducatedGuessIfTheExceptionMaybeWasHandled()` (#622)
    - Look futher into the backtrace to check if `report()` was called.
- Run the testsuite against PHP 8.2 (#624)

## 3.1.2

- Set `traces_sample_rate` to `null` by default (#616)
    - Make sure to update your `config/sentry.php`.

      Replace
      ```
      'traces_sample_rate' => (float)(env('SENTRY_TRACES_SAMPLE_RATE', 0.0)),
      ```
      with
      ```
      'traces_sample_rate' => env('SENTRY_TRACES_SAMPLE_RATE') === null ? null : (float)env('SENTRY_TRACES_SAMPLE_RATE'),
      ```
- Fix exceptions sent via the `report()` helper being marked as unhandled (#617)

## 3.1.1

- Fix missing scope information on unhandled exceptions (#611)

## 3.1.0

- Unhandled exceptions are now correctly marked as `handled: false` and displayed as such on the issues list and detail page (#608)
   - Make sure to update your `App/Exceptions/Handler.php` file to enable this new behaviour. See https://docs.sentry.io/platforms/php/guides/laravel/

## 3.0.1

- Remove incorrect checks if performance tracing should be enabled and rely on the transaction sampling decision instead (#600)
- Fix `SENTRY_RELEASE` .env variable not working when using config caching (#603)

## 3.0.0

**New features**

- We are now creating more spans to give you better insights into the performance of your application
    - Add a `http.client` span. This span indicates the time that is spent when using the Laravel HTTP client (#585)
    - Add a `http.route` span. This span indicates the time that is spent inside a controller method or route closure (#593)
    - Add a `db.transaction` span. This span indicates the time that is spent inside a database transaction (#594)
- Add support for [Dynamic Sampling](https://docs.sentry.io/product/data-management-settings/dynamic-sampling/), allowing developers to set a server-side sampling rate without the need to re-deploy their applications
    - Add support for Dynamic Sampling (#572)

**Breaking changes**

- Laravel Lumen is no longer supported
    - Drop support for Laravel Lumen (#579)
- Laravel versions 5.0 - 5.8 are no longer supported
    - Drop support for Laravel 5.x (#581)
- Remove `Sentry\Integration::extractNameForRoute()`, it's alternative `Sentry\Integration::extractNameAndSourceForRoute()` is marked as `@internal` (#580)
- Remove internal `Sentry\Integration::currentTracingSpan()`, use `SentrySdk::getCurrentHub()->getSpan()` if you were using this internal method (#592)

**Other changes**

- Set the tracing transaction name on the `Illuminate\Routing\Events\RouteMatched` instead of at the end of the request (#580)
- Remove extracting route name or controller for transaction names (#583). This unifies the transaction names to a more concise format.
- Simplify Sentry meta tag retrieval, by adding `Sentry\Laravel\Integration::sentryMeta()` (#586)
- Fix tracing with nested queue jobs (mostly when running jobs in the `sync` driver) (#592)

## 2.14.2

- Fix extracting command input resulting in errors when calling Artisan commands programatically with `null` as an argument value (#589)

## 2.14.1

- Fix not setting the correct SDK ID and version when running the `sentry:test` command (#582)
- Transaction names now only show the parameterized URL (`/some/{route}`) instead of the route name or controller class (#583)

## 2.14.0

- Fix not listening to queue events because `QueueManager` is registered as `queue` in the container and not by it's class name (#568)
- Fix status code not populated on transaction if response did not inherit from `Illuminate\Http\Response` like `Illuminate\Http\JsonResponse` (#573)
- Align Span Operations with new spec (#574)
- Fix broken `SetRequestMiddleware` on Laravel < 6.0 (#575)
- Also extract the authenticated user `email` and `username` attributes if available (#577)

## 2.13.0

- Only catch `BindingResolutionException` when trying to get the PSR-7 request object from the container

## 2.12.1

- Fix incorrect `release` and `environment` values when using the `sentry:test` command

## 2.12.0

- Add support for normalized route names when using [Laravel Lumen](https://lumen.laravel.com/docs/9.x) (#449)
- Add support for adding the user ID to the user scope when using [Laravel Sanctum](https://laravel.com/docs/9.x/sanctum) (#542)
- Allow configuration of the [`send_default_pii`](https://docs.sentry.io/platforms/php/configuration/options/#send-default-pii) SDK option with the `SENTRY_SEND_DEFAULT_PII` env variable

## 2.11.1

- Fix deprecation notice in route name extraction (#543)

## 2.11.0

- Add support for Laravel 9 (#534)
- Fix double wrapping the log channel in a `FingersCrossedHandler` on Laravel `v8.97` and newer when `action_level` option is set on the Log channel config (#534)
- Update span operation names to match what Sentry server is expecting (#533)

## 2.10.2

- Fix `sentry:test` command not having correct exit code on success

## 2.10.1

- Fix compatibility with Laravel <= 6 of the `sentry:test` and `sentry:publish` commands

## 2.10.0

- Improve output and DX for `sentry:test` and `sentry:publish` commands (#522)

## 2.9.0

- Add support for Laravel Octane (#495)
- Fix bug in Sentry log channel handler checking an undefined variable resulting in an error (#515)
- Add `action_level` configuration option for Sentry log channel which configures a Monolog `FingersCrossedHandler` (#516)

## 2.8.0

- Update phpdoc on facade for better IDE autocompletion (#504)
- Exceptions captured using log channels (Monolog) will now have the correct severity set (#505)
- Tags passed through log channels (Monolog) context are cast as string to prevent type errors (#507)
- Add options to the `artisan sentry:publish` command to better support `--no-interaction` mode (#509) 

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
