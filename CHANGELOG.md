# Changelog

## 4.2.0

The Sentry SDK team is happy to announce the immediate availability of Sentry Laravel SDK v4.2.0.

### Features

- Add new spans, measuring the time taken to queue a job [(#833)](https://github.com/getsentry/sentry-laravel/pull/833)

- Add support for `failure_issue_threshold` & `recovery_threshold` for `sentryMonitor()` method on scheduled commands [(#838)](https://github.com/getsentry/sentry-laravel/pull/838)

- Automatically flush metrics when the application terminates [(#841)](https://github.com/getsentry/sentry-laravel/pull/841)

- Add support for the W3C traceparent header [(#834)](https://github.com/getsentry/sentry-laravel/pull/834)

- Improve `php artisan sentry:test` to show internal log messages by default [(#842)](https://github.com/getsentry/sentry-laravel/pull/842)

## 4.1.2

The Sentry SDK team is happy to announce the immediate availability of Sentry Laravel SDK v4.1.2.

### Bug Fixes

- Fix unable to set `callable` for `integrations` option [(#826)](https://github.com/getsentry/sentry-laravel/pull/826)

- Fix performance traces not being collected for Laravel Lumen unless missing routes are reported [(#822)](https://github.com/getsentry/sentry-laravel/pull/822)

- Fix configuration options for queue job tracing not applying correctly [(#820)](https://github.com/getsentry/sentry-laravel/pull/820)

### Misc

- Allow newer versions of `symfony/psr-http-message-bridge` dependency [(#829)](https://github.com/getsentry/sentry-laravel/pull/829)

## 4.1.1

The Sentry SDK team is happy to announce the immediate availability of Sentry Laravel SDK v4.1.1.

### Bug Fixes

- Fix missing `sentryMonitor()` macro when command is called outside the CLI environment [(#812)](https://github.com/getsentry/sentry-laravel/pull/812)

- Don't call `terminating()` in Lumen apps below 9.1.4 [(#815)](https://github.com/getsentry/sentry-laravel/pull/815)

## 4.1.0

The Sentry SDK team is happy to announce the immediate availability of Sentry Laravel SDK v4.1.0.

### Features

- Capture SQL query bindings (parameters) in SQL query spans [(#804)](https://github.com/getsentry/sentry-laravel/pull/804)

  To enable this feature, update your `config/sentry.php` file or set the `SENTRY_TRACE_SQL_BINDINGS_ENABLED` environment variable to `true`.

  ```php
  'tracing' => [
      'sql_bindings' => true,
  ],
  ```

### Misc

- Unify backtrace origin span attributes [(#803)](https://github.com/getsentry/sentry-laravel/pull/803)
- Add `ignore_exceptions` & `ignore_transactions` to default config [(#802)](https://github.com/getsentry/sentry-laravel/pull/802)

## 4.0.0

The Sentry SDK team is thrilled to announce the immediate availability of Sentry Laravel SDK v4.0.0.

### Breaking Change

This version adds support for the underlying [Sentry PHP SDK v4.0](https://github.com/getsentry/sentry-php).
Please refer to the PHP SDK [sentry-php/UPGRADE-4.0.md](https://github.com/getsentry/sentry-php/blob/master/UPGRADE-4.0.md) guide for a complete list of breaking changes.

- This version exclusively uses the [envelope endpoint](https://develop.sentry.dev/sdk/envelopes/) to send event data to Sentry.

  If you are using [sentry.io](https://sentry.io), no action is needed.
  If you are using an on-premise/self-hosted installation of Sentry, the minimum requirement is now version `>= v20.6.0`.

- You need to have `ext-curl` installed to use the SDK.

- The `IgnoreErrorsIntegration` integration was removed. Use the `ignore_exceptions` option instead.

  ```php
  // config/sentry.php

  'ignore_exceptions' => [BadThingsHappenedException::class],
  ```

  This option performs an [`is_a`](https://www.php.net/manual/en/function.is-a.php) check now, so you can also ignore more generic exceptions.

### Features

- Enable distributed tracing for outgoing HTTP client requests [(#797)](https://github.com/getsentry/sentry-laravel/pull/797)

  This feature is only available on Laravel >= 10.14.
  When making a request using the Laravel `Http` facade, we automatically attach the `sentry-trace` and `baggage` headers.

  This behaviour can be controlled by setting `trace_propagation_targets` in your `config/sentry.php` file.

  ```php
  // config/sentry.php

  // All requests will contain the tracing headers. This is the default behaviour.
  'trace_propagation_targets' => null,

  // To turn this feature off completely, set the option to an empty array.
  'trace_propagation_targets' => [],

  // To only attach these headers to some requests, you can allow-list certain hosts.
  'trace_propagation_targets' => [
      'examlpe.com',
      'api.examlpe.com',
  ],
  ```

  Please make sure to remove any custom code that injected these headers previously.
  If you are using the `Sentry\Tracing\GuzzleTracingMiddleware` provided by our underlying PHP SDK, you must also remove it.

- Add support for Laravel Livewire 3 [(#798)](https://github.com/getsentry/sentry-laravel/pull/798)

  The SDK now creates traces and breadcrumbs for Livewire 3 as well.
  Both the class-based and Volt usage are supported.

  ```php
  // config/sentry.php

  'breadcrumbs' => [
      // Capture Livewire components in breadcrumbs
      'livewire' => true,
  ],
  'tracing' => [
      // Capture Livewire components as spans
      'livewire' => true,
  ],
  ```

- Add new fluent APIs [(#1601)](https://github.com/getsentry/sentry-php/pull/1601)

  ```php
  // Before
  $spanContext = new SpanContext();
  $spanContext->setDescription('myFunction');
  $spanContext->setOp('function');

  // After
  $spanContext = (new SpanContext())
      ->setDescription('myFunction');
      ->setOp('function');
  ```

- Simplify the breadcrumb API [(#1603)](https://github.com/getsentry/sentry-php/pull/1603)

  ```php
  // Before
  \Sentry\addBreadcrumb(
      new \Sentry\Breadcrumb(
          \Sentry\Breadcrumb::LEVEL_INFO,
          \Sentry\Breadcrumb::TYPE_DEFAULT,
          'auth',                // category
          'User authenticated',  // message (optional)
          ['user_id' => $userId] // data (optional)
      )
  );

  // After
  \Sentry\addBreadcrumb(
      category: 'auth',
      message: 'User authenticated', // optional
      metadata: ['user_id' => $userId], // optional
      level: Breadcrumb::LEVEL_INFO, // set by default
      type: Breadcrumb::TYPE_DEFAULT, // set by default
  );
  ```

- New default cURL HTTP client [(#1589)](https://github.com/getsentry/sentry-php/pull/1589)

### Misc

- The abandoned package `php-http/message-factory` was removed.
