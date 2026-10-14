<?php

declare(strict_types=1);

namespace Vernesoft\Tests\Integration;

/**
 * The integration suite's environment, and its refusal to touch a remote host.
 */
final class IntegrationEnv
{
    private static ?string $prefix = null;

    /** The key, or `null` when the suite is not asked for (every test is then skipped). */
    public static function key(): ?string
    {
        $key = getenv('VERNE_API_KEY');
        if ($key === false || $key === '') {
            return null;
        }

        $host = parse_url(self::baseUrl(), PHP_URL_HOST);
        if (! in_array($host, ['api.soft.com', 'localhost', '127.0.0.1'], true) && getenv('VERNE_ALLOW_REMOTE') !== '1') {
            throw new \RuntimeException("Refusing to run the integration suite against {$host}; set VERNE_ALLOW_REMOTE=1 to insist.");
        }

        return $key;
    }

    public static function baseUrl(): string
    {
        return rtrim(getenv('VERNE_BASE_URL') ?: 'http://api.soft.com', '/');
    }

    public static function prefix(): string
    {
        return self::$prefix ??= (getenv('VERNE_EMAIL_PREFIX') ?: 'e2e-sdkphp-'.base_convert((string) time(), 10, 36));
    }
}
