<?php

declare(strict_types=1);

namespace Fruitcake\HealthCheck\Model;

/**
 * Masks credentials in texts from exceptions before they reach the response or the log, e.g. a search engine
 * or AMQP URL with user:password@host, or a "password=..." in a DSN.
 */
class Redactor
{
    public static function redact(string $text): string
    {
        // scheme://user:password@host
        $text = (string) preg_replace('#([a-z][a-z0-9+.-]*://)[^\s/@:]+:[^\s/@]*@#i', '$1***:***@', $text);
        // user:password@host without scheme
        $text = (string) preg_replace('#(?<![\w/])[\w.\-]+:[^\s/@:]+@(?=[\w.\-])#', '***:***@', $text);

        // password=..., pwd: ..., "password":"..."
        return (string) preg_replace(
            '#((?:password|passwd|pwd|secret|token)["\']?\s*[=:]\s*["\']?)[^\s"\',;&]+#i',
            '$1***',
            $text
        );
    }
}
