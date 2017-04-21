# sentry-laravel

Laravel integration for [Sentry](https://sentry.io/).


## Laravel 5.x

Install the ``sentry/sentry-laravel`` package:

```bash
$ composer require sentry/sentry-laravel
```

Add the Sentry service provider and facade in ``config/app.php``:

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
    if ($this->shouldReport($exception)) {
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

## Laravel 4.x

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

## Lumen 5.x

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
    if ($this->shouldReport($e)) {
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

## Contributing

First, make sure you can run the test suite. Install development dependencies :

```bash
$ composer install
```

You may now use phpunit :

```bash
$ vendor/bin/phpunit
```


## Resources

* [Bug Tracker](http://github.com/getsentry/sentry-laravel/issues)
* [Code](http://github.com/getsentry/sentry-laravel)
* [Mailing List](https://groups.google.com/group/getsentry)
* [IRC](irc://irc.freenode.net/sentry>)  (irc.freenode.net, #sentry)
