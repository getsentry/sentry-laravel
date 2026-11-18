# Upgrade 4.x to 5.0

This guide lists the breaking changes in version 5.0 of the Sentry Laravel SDK.

Version 5.0 requires `sentry/sentry` 5.0. Code that uses the PHP SDK directly should also follow the
[PHP SDK upgrade guide](https://github.com/getsentry/sentry-php/blob/5.x/UPGRADE-5.0.md).

## Breaking Changes

### Hub replaced by `Sentry`

The PHP SDK removed the Hub (`Sentry\State\HubInterface`, `Sentry\State\Hub`, `SentrySdk::getCurrentHub()`).
Its state now lives in a global scope and an isolation scope.

- `Sentry\State\HubInterface` is no longer bound in the container. `app('sentry')` (or the custom alias of your
  service provider), the `Sentry` facade and the new `Sentry\Laravel\Sentry::class` binding all resolve the
  same `Sentry\Laravel\Sentry` instance.
- Replace type-hints on `HubInterface` with `Sentry`:

  ```php
  // Before (4.x)
  public function __construct(\Sentry\State\HubInterface $sentry) {}

  // After (5.0)
  public function __construct(\Sentry\Laravel\Sentry $sentry) {}
  ```

- `app('sentry')->captureException()`, `Sentry::captureException()`, `getLastEventId()`, `configureScope()`,
  `withScope()`, `pushScope()`/`popScope()`, `startTransaction()`, `getSpan()`/`setSpan()` and `getTransaction()`
  keep working through `Sentry`. The differences to the Hub are:
  - `getClient()` never returns `null`. When Sentry is not configured it returns a `Sentry\NoOpClient`, so
    replace `getClient() === null` checks with `getClient() instanceof \Sentry\NoOpClient`.
  - `addBreadcrumb()` returns `void` instead of `bool`.
  - `pushScope()` returns the pushed `Sentry\State\IsolationScope`.
  - `setSpan()` returns the `Sentry` instance.
- `Sentry` adds `getGlobalScope()`, `getIsolationScope()` and `withIsolationScope()`.

### Scope callbacks receive an `IsolationScope`

`configureScope()`, `withScope()` and `Integration::configureScope()` pass a `Sentry\State\IsolationScope`.
`Sentry\State\Scope` is now an abstract base class without setters, so type-hint the callback parameter with
`IsolationScope`:

```php
// Before (4.x)
\Sentry\configureScope(function (\Sentry\State\Scope $scope): void {
    $scope->setTag('feature', 'checkout');
});

// After (5.0)
\Sentry\configureScope(function (\Sentry\State\IsolationScope $scope): void {
    $scope->setTag('feature', 'checkout');
});
```

The `\Sentry\withScope()` function was removed from the PHP SDK. Use `\Sentry\withIsolationScope()` instead. `withScope()`
on the `Sentry` facade and `app('sentry')` keeps working:

```php
// Before (4.x)
\Sentry\withScope(function (\Sentry\State\Scope $scope) use ($exception): void {
    $scope->setTag('feature', 'checkout');

    \Sentry\captureException($exception);
});

// After (5.0)
\Sentry\withIsolationScope(function (\Sentry\State\IsolationScope $scope) use ($exception): void {
    $scope->setTag('feature', 'checkout');

    \Sentry\captureException($exception);
});
```

### `SentryHandler`

- The constructor takes a `Sentry\Laravel\Sentry` instead of a `Sentry\State\HubInterface`.
- The protected `$hub` property was renamed to `$sentry`.

### `AboutCommandIntegration`

`Sentry\Laravel\Console\AboutCommandIntegration::__invoke()` takes a `Sentry` instead of a `HubInterface`.

### Queue job scopes

A job processed by a queue worker still gets a scope of its own, which is popped when the job finishes. When the job
throws, its scope stays active until the worker has reported the exception, so the event contains the breadcrumbs, tags
and trace of the job no matter how the exception is captured: through `Integration::handles()`, with
`app('sentry')->captureException()` or `\Sentry\captureException()` in your exception handler, or by the `sentry` log
channel. The scope is popped when the worker continues with the next iteration of its loop, stops, or when the
`queue:work` command finishes. Previously the scope of a job that threw stayed active until the next job started.

Jobs that run synchronously, with the `sync` queue driver or `dispatchSync()`, no longer get a scope of their own. They
run in the scope of the request, command or job that dispatched them, so their breadcrumbs and everything they set on
the scope stay on that scope and are part of the events captured there, including the exception of a job that threw.

### Removed deprecated methods

- `Sentry\Laravel\Integration::sentryW3CTracingMeta()` was removed. It already returned an empty string.
- `Sentry\Laravel\BaseServiceProvider::couldHavePerformanceTracingEnabled()` was removed.
- `Sentry\Laravel\Features\Feature::shouldSendDefaultPii()` was removed. Read the `data_collection` option instead, for
  example `SentrySdk::getClient()->getOptions()->getDataCollection()->shouldCollectUserInfo()`.

### `send_default_pii` replaced by `data_collection`

The `send_default_pii` option (`SENTRY_SEND_DEFAULT_PII`) and the legacy data collection it controlled were removed. The
`data_collection` option now always decides which data is collected automatically. An empty array or `null` applies the
default of every option, `null` no longer keeps the legacy options.

If your config already sets `data_collection` to an array, `send_default_pii`, `breadcrumbs.sql_bindings` and
`tracing.sql_bindings` were already ignored and your configuration keeps applying. You only have to remove these
options from your config. The rest of this section is for applications where `data_collection` is missing or `null`,
which is what a config file published with 4.x contains.

The defaults collect more data than `send_default_pii` did, with either value:

- The IP address of the request and the authenticated user (id, username and email) are set on the events.
- Cookies, HTTP headers, URL query parameters and function arguments in stack frames are collected, and values whose
  name matches the built-in list of sensitive terms are replaced with `[Filtered]`.
- HTTP headers, cookies and bodies of the request and its response are attached to the transaction, and those of the
  requests sent with the HTTP client to their spans, along with the full URL.
- The bindings of SQL queries are attached to their breadcrumbs and spans as `db.query.parameter.<key>`, and the
  parameters of Redis commands to their spans.
- The data of queued jobs that were pushed as an array payload is attached to the queue spans.
- The inputs and outputs of the `laravel/ai` integration are attached to the spans, including the tool definitions,
  which are now part of the inputs.

To restrict the collection to roughly what `send_default_pii => false` collected before, opt out of these categories in
`config/sentry.php`:

```php
'data_collection' => [
    'user_info' => false,
    'cookies' => ['mode' => 'off'],
    'http_bodies' => ['incomingRequest'],
    'gen_ai' => ['inputs' => false, 'outputs' => false],
    'database_query_data' => false,
    'queues' => false,
],
```

The following changed along with it:

- The `breadcrumbs.sql_bindings` (`SENTRY_BREADCRUMBS_SQL_BINDINGS_ENABLED`) and `tracing.sql_bindings` options were
  removed, together with the `bindings` breadcrumb data and the `db.sql.bindings` span data they added. Use
  `data_collection.database_query_data` instead, which is enabled by default and also covers the parameters of Redis
  commands. The default config file sets it from `SENTRY_TRACE_SQL_BINDINGS_ENABLED`, a config file that was
  published before has to be updated to keep using that environment variable:

  ```php
  'data_collection' => [
      'database_query_data' => env('SENTRY_TRACE_SQL_BINDINGS_ENABLED', true),
  ],
  ```
- The body of requests that are not forms is collected from the raw body, bodies that cannot be parsed are sent as
  `[Filtered]`, and the query string is collected as it was received.
- Uploaded files are no longer added to the request data, and the `max_request_body_size` value `always` collects
  bodies up to 100,000 bytes instead of bodies of any size.
- The `X-Forwarded-For` and `X-Real-IP` request headers are no longer filtered by default, and the `Cookie` and
  `Set-Cookie` headers are no longer part of the collected headers. To filter additional headers, add terms to the deny
  list, a header is filtered if its name contains one of the terms:

  ```php
  'data_collection' => [
      'http_headers' => [
          'request' => ['mode' => 'denyList', 'terms' => ['x-tenant-id']],
      ],
  ],
  ```
- HTTP client spans and breadcrumbs only contain `http.query` when the URL has a query string, and its values are
  filtered.
- `sentry:publish` no longer asks whether to send default PII and no longer writes `SENTRY_SEND_DEFAULT_PII` to the
  `.env` file. The `--with-send-default-pii` option was removed.
- The `about` command no longer shows the `Send Default PII` and `Data Collection` rows.

### Options removed or changed in the PHP SDK

These options are passed from `config/sentry.php` to the PHP SDK, so update them in your config. Options that no
longer exist are ignored, a published config file that still contains them keeps working:

- `enable_tracing` was removed. Use `traces_sample_rate` or `traces_sampler`.
- `spotlight_url` was removed. Pass the URL to the `spotlight` option. A URL with a path is used as it is, `/stream`
  is only appended to a URL without a path such as `http://localhost:8969`.
- `enable_logs` (`SENTRY_ENABLE_LOGS`) and `enable_metrics` (`SENTRY_ENABLE_METRICS`) were removed. Logs and metrics are
  sent whenever their APIs or the `sentry_logs` log channel are used, and buffered logs are always flushed when the
  application terminates. To stop sending them, return `null` from the `before_send_log` or `before_send_metric`
  callback.
- `context_lines` was removed. Use `data_collection.frame_context_lines`.
- `max_value_length` was removed, the PHP SDK no longer truncates the data of the events.
- `strict_trace_propagation` was removed. Use `strict_trace_continuation`.
- `attach_metric_code_locations` and `before_send_metrics` were removed. Use `before_send_metric` to filter the metrics
  sent with `\Sentry\metrics()`.
- The `max_request_body_size` value `none` was renamed to `never`. A config that still passes `none` falls back to the
  default `medium`, so request bodies are collected until the value is changed.
- `trace_ignore_status_codes` now defaults to `[404]`, so transactions for responses with a 404 status code are no
  longer sent. This also covers matched routes that respond with a 404, for example through `abort(404)` or a missing
  route model binding. Set the option to `[]` to keep sending them.

### Changes to the data sent to Sentry

- Error events that Laravel did not name after a matched route get the name of the active transaction as their
  `transaction`, for example the transaction of a queue job, and otherwise the path of the request URL. In 4.x these
  events usually had no `transaction`.
- The span that was active when a log was recorded is sent as the `span_id` of the log instead of the
  `sentry.trace.parent_span_id` attribute. This applies to the `sentry_logs` log channel and `\Sentry\logger()`.
  `before_send_log` callbacks that read the attribute have to use `Log::getSpanId()`, and searches or alerts in Sentry
  that filter on the attribute have to be changed.

### PHP SDK APIs that are often used in Laravel applications

The [PHP SDK upgrade guide](https://github.com/getsentry/sentry-php/blob/5.x/UPGRADE-5.0.md) has the complete list and
the details. These are the changes Laravel applications run into most often:

- `SentrySdk::getCurrentHub()` was removed. Use `app('sentry')`, the `Sentry` facade or the `\Sentry\...()` functions.
- `Scope::addGlobalEventProcessor()` was removed. Use `SentrySdk::getGlobalScope()->addEventProcessor()`. Event
  processors only run for error events and transactions, no longer for check-ins, logs and metrics. Use
  `before_send_check_in`, `before_send_log` and `before_send_metric` for those.
- `\Sentry\traceMetrics()` was removed. Use `\Sentry\metrics()`, which returns the class that was called
  `TraceMetrics` before. The old Metrics API it returned in 4.x was removed.
- `\Sentry\getW3CTraceparent()` was removed. Use `\Sentry\getTraceparent()`.
- The `segment` of the user was removed. A `segment` key passed to `setUser()` is stored as user metadata instead, use
  a tag or a context for it.
- `Sentry\Monolog\Handler` was removed, and `Sentry\Monolog\BreadcrumbHandler`,
  `Sentry\Monolog\LogToSentryIssueHandler` and `Sentry\Monolog\ExceptionToSentryIssueHandler` no longer take a
  `HubInterface` as their first constructor argument. Update the log channels in `config/logging.php` that create these
  handlers themselves.
