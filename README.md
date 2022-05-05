<p align="center">
  <a href="https://sentry.io/?utm_source=github&utm_medium=logo" target="_blank">
    <img src="https://sentry-brand.storage.googleapis.com/sentry-wordmark-dark-280x84.png" alt="Sentry" width="280" height="84">
  </a>
</p>

_Bad software is everywhere, and we're tired of it. Sentry is on a mission to help developers write better software faster, so we can get back to enjoying technology. If you want to join us [<kbd>**Check out our open positions**</kbd>](https://sentry.io/careers/)_

# Official Sentry SDK for Laravel

[![Build Status](https://img.shields.io/github/checks-status/getsentry/sentry-laravel/master)](https://github.com/getsentry/sentry-laravel/actions)
[![Composer page link -- version](https://img.shields.io/packagist/v/getsentry/sentry-lararvel.svg)](https://packagist.org/packages/sentry/sentry-laravel)
[![Discord](https://img.shields.io/discord/621778831602221064)](https://discord.gg/cWnMQeA)

This is the official Laravel SDK for [Sentry](https://sentry.io/)

---

## Getting Started

The installation step below work on the latest versions of the Laravel framework (8.x and 9.x).

For other Laravel or Lumen versions see:

- [Laravel 8.x & 9.x](https://docs.sentry.io/platforms/php/guides/laravel/)
- [Laravel 5.x, 6.x & 7.x](https://docs.sentry.io/platforms/php/guides/laravel/other-versions/laravel5-6-7/)
- [Laravel 4.x](https://docs.sentry.io/platforms/php/guides/laravel/other-versions/laravel4/)
- [Lumen](https://docs.sentry.io/platforms/php/guides/laravel/other-versions/lumen/)

### Install

Install the `sentry/sentry-laravel` package:

```bash
composer require sentry/sentry-laravel
```

Enable capturing unhandled exception to report to Sentry by making the following change to your `App/Exceptions/Handler.php`:

```php {filename:App/Exceptions/Handler.php}
public function register()
{
    $this->reportable(function (Throwable $e) {
        if (app()->bound('sentry')) {
            app('sentry')->captureException($e);
        }
    });
}
```

> Alternatively, you can configure Sentry in your [Laravel Log Channel](https://docs.sentry.io/platforms/php/guides/laravel/usage/#log-channels), allowing you to log `info` and `debug` as well.

### Configure

Configure the Sentry DSN with this command:

```shell
php artisan sentry:publish --dsn=___PUBLIC_DSN___
```

It creates the config file (`config/sentry.php`) and adds the `DSN` to your `.env` file.

```shell {filename:.env}
SENTRY_LARAVEL_DSN=___PUBLIC_DSN___
```

### Usage

```php
try {
    $this->functionFailsForSure();
} catch (\Throwable $exception) {
    \Sentry\captureException($exception);
}
```

- To learn more about how to use the SDK [refer to our docs](https://docs.sentry.io/platforms/php/guides/laravel/)

## Laravel Version Compatibility

- Laravel `<= 4.2.x` is supported until `0.8.x`
- Laravel `<= 5.7.x` on PHP `<= 7.0` is supported until `0.11.x`
- Laravel `>= 5.x.x` on PHP `>= 7.1` is supported in all versions
- Laravel `>= 6.x.x` on PHP `>= 7.2` is supported starting from `1.2.0`
- Laravel `>= 7.x.x` on PHP `>= 7.2` is supported starting from `1.7.0`
- Laravel `>= 8.x.x` on PHP `>= 7.3` is supported starting from `1.9.0`
- Laravel `>= 9.x.x` on PHP `>= 8.0` is supported starting from `2.11.0`

Please note that of version `>= 2.0.0` we require PHP Version `>= 7.2` because we are using our new [PHP SDK](https://github.com/getsentry/sentry-php) underneath.

## Contributing to the SDK

Please refer to [CONTRIBUTING.md](CONTRIBUTING.md).

## Getting help/support

If you need help setting up or configuring the Laravel SDK (or anything else in the Sentry universe) please head over to the [Sentry Community on Discord](https://discord.com/invite/Ww9hbqr). There is a ton of great people in our Discord community ready to help you!

## Resources

- [![Documentation](https://img.shields.io/badge/documentation-sentry.io-green.svg)](https://docs.sentry.io/quickstart/)
- [![Forum](https://img.shields.io/badge/forum-sentry-green.svg)](https://forum.sentry.io/c/sdks)
- [![Discord](https://img.shields.io/discord/621778831602221064)](https://discord.gg/Ww9hbqr)
- [![Stack Overflow](https://img.shields.io/badge/stack%20overflow-sentry-green.svg)](http://stackoverflow.com/questions/tagged/sentry)
- [![Twitter Follow](https://img.shields.io/twitter/follow/getsentry?label=getsentry&style=social)](https://twitter.com/intent/follow?screen_name=getsentry)

## License

Licensed under the Apache 2.0 license, see [`LICENSE`](LICENSE)
