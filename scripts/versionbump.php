<?php

$re = '/\d+\.\d+.\d+(?:-\w+(?:\.\w+)?)?/m';

$result = preg_replace($re, $argv[1], file_get_contents('../src/Sentry/Laravel/Version.php'));
file_puts_contents('../src/Sentry/Laravel/Version.php', $result);