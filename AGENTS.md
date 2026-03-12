# AGENTS.md

## Overview

- `sentry/sentry-laravel` is a Composer library, not a runnable Laravel app.
- It adapts `sentry/sentry` to Laravel and Lumen and adds optional integrations
  for tracing, logs, queue jobs, cache, notifications, Folio, Pennant,
  Livewire, storage, and scheduler check-ins.
- The main entry points are:
  - `src/Sentry/Laravel/ServiceProvider.php`: package bootstrap, client/hub
    registration, feature registration, event binding, and log channel setup.
  - `src/Sentry/Laravel/Tracing/ServiceProvider.php`: request tracing
    middleware, tracing event subscriber, route/view instrumentation, and
    default tracing integrations.
  - `src/Sentry/Laravel/Integration.php`: public helper methods such as
    `handles()`, meta tag helpers, transaction naming helpers, and model
    violation reporters.
  - `config/sentry.php`: the user-facing config surface.
- Package discovery is declared in `composer.json`, which auto-registers both
  service providers and the `Sentry` facade alias.
- For Laravel `11+`, unhandled exception capture is part of the documented
  public setup flow through `Integration::handles($exceptions)` in
  `bootstrap/app.php`; the service provider does not replace that step.
- Important code areas:
  - `src/Sentry/Laravel/Features/`: optional framework/package integrations.
  - `src/Sentry/Laravel/Tracing/`: HTTP transaction lifecycle and span logic.
  - `src/Sentry/Laravel/Http/`: Laravel-specific request capture and flushing.
  - `src/Sentry/Laravel/Console/`: `sentry:publish`, `sentry:test`, and
    `about` integration.
  - `src/Sentry/Laravel/Integration/ModelViolations/`: Eloquent model
    violation reporters.
  - `test/Sentry/`: the full PHPUnit suite.

## Compatibility Rules

- Shipped code must remain valid on PHP `7.2` unless the package minimum is
  intentionally being raised. `composer.json` still allows `^7.2 | ^8.0`.
- This package supports `illuminate/support` `^6` through `^13`. Keep
  cross-version behavior intact.
- Use feature detection, not hard framework assumptions. Preserve the existing
  style of `class_exists()`, `method_exists()`, and targeted `version_compare()`
  checks for newer Laravel APIs.
- Do not assume optional packages are installed. Folio, Pennant, Livewire,
  Lighthouse, Sanctum, Octane, and newer Laravel Context APIs are all guarded
  in the codebase and tests.
- Treat `.github/workflows/ci.yaml` as the real compatibility matrix. A local
  `composer tests` run only validates the currently installed dependency set.
- `Spotlight` is treated like a DSN-present boot path. Do not accidentally gate
  runtime setup on DSN alone.
- `AboutCommand` only exists on newer Laravel versions, HTTP client trace
  propagation depends on newer `Factory` APIs, and scheduler background context
  handoff switches behavior on Laravel `12.40.2+`.

## Editing Guidance

- Keep Laravel-specific config keys out of the base PHP SDK options. If you add
  new Laravel-only config, update `ServiceProvider::LARAVEL_SPECIFIC_OPTIONS`.
- If a config value should be resolved from the container, update
  `ServiceProvider::OPTIONS_TO_RESOLVE_FROM_CONTAINER`.
- If you change tracing default integrations, keep
  `config/sentry.php` and `Tracing/ServiceProvider::DEFAULT_INTEGRATIONS`
  synchronized.
- `Features\Feature` intentionally swallows setup failures so host apps do not
  break. Regressions can fail silently; tests are the real safety net.
- Preserve DSN/Spotlight boot gating in `ServiceProvider` and
  `Tracing/ServiceProvider`. Some features register unconditionally but only
  become active in `boot()`.
- Keep route transaction names URI-based. `Integration::extractNameAndSourceForRoute()`
  intentionally uses normalized route URIs, not Laravel route names.
- Do not remove `Tracing\Middleware::signalRouteWasMatched()`. Missing-route
  dropping depends on it.
- Preserve span push/pop symmetry. Queue, notifications, HTTP client,
  scheduler, and tracing event handlers all depend on careful scope restoration.
- `Tracing\Middleware` is stateful across requests in Octane-style workers.
  Reset logic around `transaction`, `appSpan`, `didRouteMatch`, and
  `bootedTimestamp` matters.
- `TransactionFinisher` is singleton-based on purpose so terminate callbacks are
  only registered once and `afterResponse()` work can still be traced.
- The log drivers are split on purpose:
  - `src/Sentry/Laravel/LogChannel.php` and `src/Sentry/Laravel/SentryHandler.php`
    turn Monolog records into classic Sentry events.
  - `src/Sentry/Laravel/Logs/LogChannel.php` and
    `src/Sentry/Laravel/Logs/LogsHandler.php` send structured logs.
- Keep the `action_level` handling in both log channels. It is intentionally
  unset to avoid double `FingersCrossedHandler` wrapping on newer Laravel
  versions.
- Storage instrumentation is decorator-based. If Laravel adds new filesystem
  methods, update the wrappers under `src/Sentry/Laravel/Features/Storage/`
  instead of assuming passthrough behavior is automatic.
- Model violation reporters may defer reporting with `app()->terminating()`.
  Preserve duplicate suppression and callback chaining behavior.
- `Integration::flushEvents()` and several classes under `Tracing/` and
  `Features/` are implementation details even if public in PHP terms. Prefer
  changing documented surfaces first.
- `src/Sentry/Laravel/Version.php` is manually maintained. If version stamping
  changes, update it deliberately.

## Update Together

- Config or env var changes usually require synchronized updates in:
  - `config/sentry.php`
  - `src/Sentry/Laravel/ServiceProvider.php`
  - `src/Sentry/Laravel/Console/PublishCommand.php`
  - the runtime consumer of the new option in `Features/`, `Http/`,
    `EventHandler.php`, or `Tracing/`
  - the relevant PHPUnit files under `test/Sentry/`
- Route naming or HTTP transaction lifecycle changes usually touch:
  - `src/Sentry/Laravel/Integration.php`
  - `src/Sentry/Laravel/EventHandler.php`
  - `src/Sentry/Laravel/Tracing/EventHandler.php`
  - `src/Sentry/Laravel/Tracing/Middleware.php`
  - `src/Sentry/Laravel/Tracing/Routing/*`
- Logging changes usually touch:
  - `src/Sentry/Laravel/ServiceProvider.php`
  - `src/Sentry/Laravel/Features/LogIntegration.php`
  - `src/Sentry/Laravel/LogChannel.php`
  - `src/Sentry/Laravel/SentryHandler.php`
  - `src/Sentry/Laravel/Logs/LogChannel.php`
  - `src/Sentry/Laravel/Logs/LogsHandler.php`
  - `config/sentry.php`
- Storage changes usually touch `src/Sentry/Laravel/Features/Storage/*` and
  `test/Sentry/Features/StorageIntegrationTest.php`.
- Scheduler monitor changes usually touch
  `src/Sentry/Laravel/Features/ConsoleSchedulingIntegration.php` and
  `test/Sentry/Features/ConsoleSchedulingIntegrationTest.php`.
- Model violation changes usually touch
  `src/Sentry/Laravel/Integration.php`,
  `src/Sentry/Laravel/Integration/ModelViolations/*`, and
  `test/Sentry/Integration/ModelViolationReportersTest.php`.

## Test Expectations

- Add tests with every behavior change. This is a library repo with broad
  integration coverage.
- New tests belong under `test/Sentry/`, not `tests/`.
- `test/Sentry/TestCase.php` is the shared Orchestra Testbench harness. It
  captures Sentry events, transactions, and check-ins in memory via
  `before_send`, `before_send_transaction`, and a global processor.
- Prefer targeted PHPUnit runs while iterating, then run `composer check`.
- After each editing session, run `composer cs-fix` and `composer phpstan`.
  Before handing back substantive code changes, run `composer check` when
  feasible and call out anything you could not run.
- High-value targeted suites:
  - service provider and config: `test/Sentry/ServiceProviderTest.php`,
    `test/Sentry/ServiceProviderWithoutDsnTest.php`,
    `test/Sentry/ServiceProviderWithEnvironmentFromConfigTest.php`,
    `test/Sentry/ServiceProviderWithCustomAliasTest.php`,
    `test/Sentry/Laravel/LaravelIntegrationsConfigOptionTest.php`,
    `test/Sentry/IntegrationMetaTagTest.php`
  - tracing and HTTP: `test/Sentry/Tracing/EventHandlerTest.php`,
    `test/Sentry/Features/RouteIntegrationTest.php`,
    `test/Sentry/Features/ViewEngineDecoratorTest.php`,
    `test/Sentry/Features/HttpClientIntegrationTest.php`,
    `test/Sentry/Features/DatabaseIntegrationTest.php`,
    `test/Sentry/Http/LaravelRequestFetcherTest.php`
  - feature integrations: `test/Sentry/Features/QueueIntegrationTest.php`,
    `test/Sentry/Features/CacheIntegrationTest.php`,
    `test/Sentry/Features/NotificationsIntegrationTest.php`,
    `test/Sentry/Features/FolioPackageIntegrationTest.php`,
    `test/Sentry/Features/PennantPackageIntegrationTest.php`,
    `test/Sentry/Features/ConsoleSchedulingIntegrationTest.php`,
    `test/Sentry/Features/StorageIntegrationTest.php`,
    `test/Sentry/Features/LogIntegrationTest.php`,
    `test/Sentry/Features/LogLogsIntegrationTest.php`
  - model violations: `test/Sentry/Integration/ModelViolationReportersTest.php`
- Some integrations are only partially covered. If you change Livewire,
  `continue_after_response`, view tracing failure paths, storage cloud adapters,
  or scheduler background fallbacks, add focused coverage.

## Tools And Commands

- Use Composer scripts as the canonical local tooling:
  - `composer install`
  - `composer tests`
  - `composer phpstan`
  - `composer cs-check`
  - `composer cs-fix`
  - `composer check`
- `phpstan.neon` only analyzes `src` and uses a baseline at level `1`. Static
  analysis is helpful but not a substitute for targeted PHPUnit coverage.
- `phpunit.xml` is strict about unexpected output, so noisy debug output will
  fail tests.
- Prefer Composer scripts over `make develop`. The `Makefile` is light wrapper
  code and its `develop` target also changes local git config, which is not
  necessary for normal agent work.
- This repo is a package, so do not expect a real app entrypoint or a useful
  `php artisan serve` workflow at the repository root.

## Docs And Release Notes

- Do not update `README.md` as part of normal agent work unless the user
  explicitly asks for it. Documentation changes are handled manually.
- Do not maintain `CHANGELOG.md` incrementally. It is typically created or
  refreshed manually before a release rather than updated with each change.
- If a code change affects installation, configuration, tracing, logging, or
  other user-facing behavior, call out the likely README or release-note follow
  up in your summary instead of editing those files automatically.
- Keep `PublishCommand` behavior and config examples internally consistent in
  code, but leave README synchronization to the manual docs workflow.
- Release automation in `.github/workflows/publish-release.yaml` is a
  maintainer-only Craft workflow; do not modify release steps casually.
