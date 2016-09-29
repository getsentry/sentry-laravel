<?php

return array(
    'dsn' => env('SENTRY_DSN', 'https://e9ebbd88548a441288393c457ec90441:399aaee02d454e2ca91351f29bdc3a07@sentry.io/3235'),

    // capture release as git sha
    // 'release' => trim(exec('git log --pretty="%h" -n1 HEAD')),

    // Capture bindings on SQL queries
    'breadcrumbs.sql_bindings' => false,
);
