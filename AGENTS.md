# AGENTS.md

## Overview

- `sentry/sentry-laravel` is a Composer library, not a runnable Laravel app.
- It adapts `sentry/sentry` to Laravel and Lumen.
- Use this file for repo-specific constraints that are easy to miss, and
  explore the codebase for current implementation details.

## Compatibility Rules

- Shipped code must remain valid on PHP `7.2` unless the package minimum is
  intentionally being raised. `composer.json` still allows `^7.2 | ^8.0`.
- This package supports `illuminate/support` `^6` through `^13`. Keep
  cross-version behavior intact.
- For Laravel `11+`, unhandled exception capture is part of the documented
  public setup flow through `Integration::handles($exceptions)` in
  `bootstrap/app.php`; the service provider does not replace that step.
- Use feature detection, not hard framework assumptions. Preserve the existing
  style of `class_exists()`, `method_exists()`, and targeted `version_compare()`
  checks for newer Laravel APIs.
- Do not assume optional packages are installed. Folio, Pennant, Livewire,
  Lighthouse, Sanctum, Octane, and newer Laravel Context APIs are all guarded
  in the codebase and tests.
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
- If you change tracing default integrations, keep `config/sentry.php` and
  `Tracing/ServiceProvider::DEFAULT_INTEGRATIONS` synchronized.
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
- Classic Sentry event logging and structured logs use separate channels and
  handlers on purpose.
- Keep the `action_level` handling in both log channels. It is intentionally
  unset to avoid double `FingersCrossedHandler` wrapping on newer Laravel
  versions.
- Storage instrumentation is decorator-based. If Laravel adds new filesystem
  methods, update the storage wrappers instead of assuming passthrough behavior
  is automatic.
- Model violation reporters may defer reporting with `app()->terminating()`.
  Preserve duplicate suppression and callback chaining behavior.
- `Integration::flushEvents()` and several classes under `Tracing/` and
  `Features/` are implementation details even if public in PHP terms. Prefer
  changing documented surfaces first.
- Version constants in `src/Sentry/Laravel/Version.php` are updated by the
  release action. Do not modify them manually as part of normal development
  changes.

## Test Expectations

- Add tests with every behavior change. This is a library repo with broad
  integration coverage.
- New tests belong under `test/Sentry/`, not `tests/`.
- `test/Sentry/TestCase.php` is the shared Orchestra Testbench harness. It
  captures Sentry events, transactions, and check-ins in memory via
  `before_send`, `before_send_transaction`, and a global processor.
- Prefer targeted PHPUnit runs while iterating.
- After editing files, run the relevant formatting, lint, and test commands for
  the code you changed.
- Before handing back substantive code changes, run `composer check` when
  feasible and call out anything you could not run.
- Some integrations are only partially covered. If you change Livewire,
  `continue_after_response`, view tracing failure paths, storage cloud adapters,
  or scheduler background fallbacks, add focused coverage.

## Tools And Commands

- `phpstan.neon` only analyzes `src` and uses a baseline at level `1`. Static
  analysis is helpful but not a substitute for targeted PHPUnit coverage.
- `phpunit.xml` is strict about unexpected output, so noisy debug output will
  fail tests.
- Prefer Composer scripts over `make develop`. The `Makefile` is a light
  wrapper and its `develop` target also changes local git config, which is not
  necessary for normal agent work.
- This repo is a package, so do not expect a real app entrypoint or a useful
  `php artisan serve` workflow at the repository root.

## Docs And Release Notes

- `README.md` and `CHANGELOG.md` are updated manually during releases, so do
  not modify them as part of normal development changes.
- If a change may require updates in the separate documentation repo, ask the
  user whether to review `../sentry-docs` if that sibling checkout exists. If
  it does not exist, ask the user for the local docs path first. If they opt
  in, update that repo's `master` branch when safe, use git worktrees to
  inspect the relevant docs, and suggest any needed changes to avoid stale
  documentation.
- If a code change affects installation, configuration, tracing, logging, or
  other user-facing behavior, call out the likely README or release-note follow
  up in your summary instead of editing those files automatically.
- Keep `PublishCommand` behavior and config examples internally consistent in
  code, but leave README synchronization to the manual docs workflow.
- Release automation in `.github/workflows/publish-release.yaml` is a
  maintainer-only Craft workflow; do not modify release steps casually.

## CI Notes

- `.github/workflows/ci.yaml` runs the PHPUnit compatibility matrix across PHP
  and Laravel/Testbench combinations.
- `.github/workflows/cs.yaml` runs PHP-CS-Fixer and PHPStan on a single recent
  PHP version rather than across the full test matrix.
