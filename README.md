<p align="center">
    <a href="https://sentry.io" target="_blank" align="center">
        <img src="https://sentry.io/_static/getsentry/images/branding/png/sentry-horizontal-black.png" width="280">
    </a>
</p>

# Sentry for Laravel

[![Build Status](https://secure.travis-ci.org/getsentry/sentry-laravel.png?branch=master)](http://travis-ci.org/getsentry/sentry-laravel)
[![Total Downloads](https://img.shields.io/packagist/dt/sentry/sentry-laravel.svg?style=flat-square)](https://packagist.org/packages/sentry/sentry-laravel)
[![Downloads per month](https://img.shields.io/packagist/dm/sentry/sentry-laravel.svg?style=flat-square)](https://packagist.org/packages/sentry/sentry-laravel)
[![Latest stable version](https://img.shields.io/packagist/v/sentry/sentry-laravel.svg?style=flat-square)](https://packagist.org/packages/sentry/sentry-laravel)
[![License](http://img.shields.io/packagist/l/sentry/sentry-laravel.svg?style=flat-square)](https://packagist.org/packages/sentry/sentry-laravel)

Laravel integration for [Sentry](https://sentry.io/).


## Installation

### Laravel 5.x

Install the ``sentry/sentry-laravel`` package:

```bash
$ composer require sentry/sentry-laravel
```

If you're on Laravel 5.4 or earlier, you'll need to add the following to your ``config/app.php``:

```php
'providers' => array(
    // ...
    Sentry\SentryLaravel\SentryLaravelServiceProvider::class,
)

'aliases' => array(
    // ...
    'Sentry' => Sentry\SentryLaravel\SentryFacade::class,
)
```

Add Sentry reporting to ``app/Exceptions/Handler.php``:

```php
public function report(Exception $exception)
{
    if (app()->bound('sentry') && $this->shouldReport($exception)) {
        app('sentry')->captureException($exception);
    }

    parent::report($exception);
}
```

Create the Sentry configuration file (``config/sentry.php``):

```bash
$ php artisan vendor:publish --provider="Sentry\SentryLaravel\SentryLaravelServiceProvider"
```

Add your DSN to ``.env``:

```
SENTRY_DSN=https://public:secret@sentry.example.com/1
```

### Laravel 4.x

Install the ``sentry/sentry-laravel`` package:

```bash
$ composer require sentry/sentry-laravel
```

Add the Sentry service provider and facade in ``config/app.php``:

```php
'providers' => array(
    // ...
    'Sentry\SentryLaravel\SentryLaravelServiceProvider',
)

'aliases' => array(
    // ...
    'Sentry' => 'Sentry\SentryLaravel\SentryFacade',
)
```

Create the Sentry configuration file (``config/sentry.php``):

```bash
$ php artisan config:publish sentry/sentry-laravel
```

### Lumen 5.x

Install the ``sentry/sentry-laravel`` package:

```bash
$ composer require sentry/sentry-laravel
```

Register Sentry in ``bootstrap/app.php``:

```php
$app->register('Sentry\SentryLaravel\SentryLumenServiceProvider');

# Sentry must be registered before routes are included
require __DIR__ . '/../app/Http/routes.php';
```

Add Sentry reporting to ``app/Exceptions/Handler.php``:

```php
public function report(Exception $e)
{
    if (app()->bound('sentry') && $this->shouldReport($e)) {
        app('sentry')->captureException($e);
    }

    parent::report($e);
}
```

Create the Sentry configuration file (``config/sentry.php``):

```php
<?php

return array(
    'dsn' => '___DSN___',

    // capture release as git sha
    // 'release' => trim(exec('git log --pretty="%h" -n1 HEAD')),

    // Capture bindings on SQL queries
    'breadcrumbs.sql_bindings' => true,

    // Capture default user context
    'user_context' => true,
);
```

## Testing with Artisan

You can test your configuration using the provided ``artisan`` command:

```bash
$ php artisan sentry:test
[sentry] Client configuration:
-> server: https://app.getsentry.com/api/3235/store/
-> project: 3235
-> public_key: e9ebbd88548a441288393c457ec90441
-> secret_key: 399aaee02d454e2ca91351f29bdc3a07
[sentry] Generating test event
[sentry] Sending test event with ID: 5256614438cf4e0798dc9688e9545d94
```

## Adding Context

The mechanism to add context will vary depending on which version of Laravel you're using, but the general approach is the same. Find a good entry point to your application in which the context you want to add is available, ideally early in the process.

In the following example, we'll use a middleware:

```php
namespace App\Http\Middleware;

use Closure;

class SentryContext
{
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request $request
     * @param  \Closure                 $next
     *
     * @return mixed
     */
    public function handle($request, Closure $next)
    {
        if (app()->bound('sentry')) {
            /** @var \Raven_Client $sentry */
            $sentry = app('sentry');

            // Add user context
            if (auth()->check()) {
                $sentry->user_context([...]);
            } else {
                $sentry->user_context(['id' => null]);
            }

            // Add tags context
            $sentry->tags_context([...]);
        }

        return $next($request);
    }
}
```

## Displaying the error ID

When something goes wrong and you get a customer email in your inbox it would be nice if they could give you some kind of identitifier for the error they are seeing.

Luckily Sentry provides you with just that by adding one of the following options to your error view.

```php
// Using the Sentry facade
$errorID = Sentry::getLastEventID();

// Or without the Sentry facade (Lumen)
$errorID = app('sentry')->getLastEventID();
```

This could look something like this in for example your `resources/views/error/500.blade.php`:

```blade
@if(!empty(Sentry::getLastEventID()))
    <p>Please send this ID with your support request: {{ Sentry::getLastEventID() }}.</p>
@endif
```

This ID can be searched for in the Sentry interface allowing you to find the error quickly.


## Contributing

Dependencies are managed through composer:

```
$ composer install
```

Tests can then be run via phpunit:

```
$ vendor/bin/phpunit
```


## Community

* [Bug Tracker](http://github.com/getsentry/sentry-laravel/issues)
* [Code](http://github.com/getsentry/sentry-laravel)
* [Mailing List](https://groups.google.com/group/getsentry)
* [IRC](irc://irc.freenode.net/sentry>)  (irc.freenode.net, #sentry)
