<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        <title>Laravel</title>

    </head>
    <body>
<div class="content">
    <div class="title">Something went wrong.</div>

    @if(app()->bound('sentry') && app('sentry')->getLastEventId())
        <div class="subtitle">Error ID: {{ app('sentry')->getLastEventId() }}</div>
        <script src="https://browser.sentry-cdn.com/{% sdk_version sentry.javascript.browser %}/bundle.min.js" crossorigin="anonymous"></script>
        <script>
            Sentry.init({ dsn: '___PUBLIC_DSN___' });
            Sentry.showReportDialog({ eventId: '{{ app('sentry')->getLastEventId() }}' });
        </script>
    @endif
</div>

</body>
</html>
