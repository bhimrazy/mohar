#!/usr/bin/env php
<?php

/*
 * Docker HEALTHCHECK for the web container: GET /up on the local app server. The Host header is APP_URL's host,
 * because TrustHosts rejects any other host outside local; healthy means HTTP 200 within 3 seconds.
 */
$host = parse_url((string) (getenv('APP_URL') ?: 'http://localhost'), PHP_URL_HOST) ?: 'localhost';
$port = (string) (getenv('HEALTHCHECK_PORT') ?: '8080');

$context = stream_context_create(['http' => [
    'timeout' => 3,
    'header' => "Host: {$host}\r\nUser-Agent: healthcheck\r\n",
    'ignore_errors' => true,
]]);

$body = @file_get_contents("http://127.0.0.1:{$port}/up", false, $context);
$status = isset($http_response_header[0]) && preg_match('{^HTTP/\S+\s+(\d{3})}', $http_response_header[0], $match)
    ? (int) $match[1]
    : 0;

if ($status !== 200) {
    fwrite(STDERR, "GET /up returned ".($status ?: 'no response')."\n");
    exit(1);
}
