# Changelog

## 4.0.0

The Sentry SDK team is thrilled to announce the immediate availability of Sentry Laravel SDK v4.0.0.

# Breaking Change

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

# Features

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

# Misc

- The abandoned package `php-http/message-factory` was removed.
