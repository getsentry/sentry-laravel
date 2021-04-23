<p align="center">
    <a href="https://sentry.io" target="_blank" align="center">
        <img src="https://sentry-brand.storage.googleapis.com/sentry-logo-black.png" width="280">
    </a>
</p>

# Sentry for Laravel

[![Build Status](https://secure.travis-ci.org/getsentry/sentry-laravel.png?branch=master)](http://travis-ci.org/getsentry/sentry-laravel)
[![Total Downloads](https://img.shields.io/packagist/dt/sentry/sentry-laravel.svg?style=flat-square)](https://packagist.org/packages/sentry/sentry-laravel)
[![Downloads per month](https://img.shields.io/packagist/dm/sentry/sentry-laravel.svg?style=flat-square)](https://packagist.org/packages/sentry/sentry-laravel)
[![Latest stable version](https://img.shields.io/packagist/v/sentry/sentry-laravel.svg?style=flat-square)](https://packagist.org/packages/sentry/sentry-laravel)
[![License](http://img.shields.io/packagist/l/sentry/sentry-laravel.svg?style=flat-square)](https://packagist.org/packages/sentry/sentry-laravel)

Laravel integration for [Sentry](https://sentry.io/).

## Laravel Version Compatibility

- Laravel `<= 4.2.x` is supported until `0.8.x`
- Laravel `<= 5.7.x` on PHP `<= 7.0` is supported until `0.11.x`
- Laravel `>= 5.x.x` on PHP `>= 7.1` is supported in all versions
- Laravel `>= 6.x.x` on PHP `>= 7.2` is supported starting from `1.2.0`
- Laravel `>= 7.x.x` on PHP `>= 7.2` is supported starting from `1.7.0`
- Laravel `>= 8.x.x` on PHP `>= 7.3` is supported starting from `1.9.0`

Please note that of version `>= 2.0.0` we require PHP Version `>= 7.2` because we are using our new [PHP SDK](https://github.com/getsentry/sentry-php) underneath. 

## Installation

- [Laravel 7.x & 8.x](https://docs.sentry.io/platforms/php/guides/laravel/)
- [Laravel 5.x & 6.x](https://docs.sentry.io/platforms/php/guides/laravel/configuration/other-versions/laravel5-6/)
- [Laravel 4.x](https://docs.sentry.io/platforms/php/guides/laravel/configuration/other-versions/laravel4/)
- [Lumen](https://docs.sentry.io/platforms/php/guides/laravel/configuration/other-versions/lumen/)

## Contributing

Dependencies are managed through composer:

```
$ composer install
```

Tests can then be run via phpunit:

```
$ vendor/bin/phpunit
```

## Links

* [Documentation](https://docs.sentry.io/platforms/php/guides/laravel/)
* [Bug Tracker](http://github.com/getsentry/sentry-laravel/issues)
* [Code](http://github.com/getsentry/sentry-laravel)
