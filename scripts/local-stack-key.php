<?php

/**
 * A throwaway tenant and API key on a LOCAL Nautilus stack, for the integration suite.
 *
 *   php scripts/local-stack-key.php create <state.json>   register an e2e- tenant, mint keys
 *   php scripts/local-stack-key.php env    <state.json>   print `export VERNE_…=…` lines
 *   php scripts/local-stack-key.php revoke <state.json>   revoke the keys
 *
 * A port of `vrn-sdk-node/scripts/local-stack-key.mjs`. It registers the way the
 * dashboard's sign-up form does (identity-service browser flow, then
 * /auth/registration/tenant) and mints keys through the dashboard API with that session.
 * The tenant's email is `e2e-sdkphp-<run>@e2e.soft.com`; nautilus'
 * `make purge-e2e E2E_PREFIXES=e2e-sdkphp-<run>` sweeps it and the end users the suite
 * creates afterwards. The state file holds secrets (mode 0600); nothing here prints a key
 * unless you ask for `env`.
 *
 * Refuses any API host but api.soft.com / localhost: this is not for production.
 */

declare(strict_types=1);

require __DIR__.'/../vendor/autoload.php';

use GuzzleHttp\Client;
use Psr\Http\Message\ResponseInterface;

$api = rtrim(getenv('VERNE_BASE_URL') ?: 'http://api.soft.com', '/');
$host = parse_url($api, PHP_URL_HOST);
if (! in_array($host, ['api.soft.com', 'localhost', '127.0.0.1'], true)) {
    fwrite(STDERR, "refusing non-local API {$api}\n");
    exit(1);
}

$http = new Client(['http_errors' => false, 'timeout' => 30, 'allow_redirects' => false]);

/** @var array<string, string> $jar */
$jar = [];
$absorb = static function (ResponseInterface $res) use (&$jar): void {
    foreach ($res->getHeader('Set-Cookie') as $line) {
        $pair = explode(';', $line, 2)[0];
        $i = strpos($pair, '=');
        if ($i !== false) {
            $jar[trim(substr($pair, 0, $i))] = substr($pair, $i + 1);
        }
    }
};
$cookieHeader = static function () use (&$jar): string {
    return implode('; ', array_map(static fn ($k, $v) => "{$k}={$v}", array_keys($jar), $jar));
};

$mintKey = static function (string $session, string $name, array $scopes) use ($http, $api): array {
    $res = $http->post("{$api}/dashboard/tenant/keys", [
        'headers' => ['Content-Type' => 'application/json', 'Cookie' => "ory_kratos_session={$session}"],
        'body' => json_encode(['name' => $name, 'scopes' => $scopes]),
    ]);
    if ($res->getStatusCode() !== 201) {
        throw new RuntimeException('key creation: HTTP '.$res->getStatusCode());
    }

    return json_decode((string) $res->getBody(), true);
};

$rnd = static fn (): string => bin2hex(random_bytes(5));

[$cmd, $file] = array_slice($argv, 1) + [null, null];
if ($file === null) {
    fwrite(STDERR, "usage: local-stack-key.php create|env|revoke <state.json>\n");
    exit(2);
}

switch ($cmd) {
    case 'create':
        $runId = base_convert((string) time(), 10, 36).substr($rnd(), 0, 5);
        $prefix = "e2e-sdkphp-{$runId}";
        $password = 'Pw-'.$rnd().'-'.$rnd().'-9!';

        $flowRes = $http->get("{$api}/auth/self-service/registration/browser?identity_schema=tenant", [
            'headers' => ['Accept' => 'application/json'],
        ]);
        $absorb($flowRes);
        if ($flowRes->getStatusCode() !== 200) {
            throw new RuntimeException('registration flow: HTTP '.$flowRes->getStatusCode());
        }
        $flow = json_decode((string) $flowRes->getBody(), true);
        $csrf = null;
        foreach ($flow['ui']['nodes'] ?? [] as $node) {
            if (($node['attributes']['name'] ?? null) === 'csrf_token') {
                $csrf = $node['attributes']['value'];
            }
        }

        $reg = $http->post("{$api}/auth/registration/tenant", [
            'headers' => ['Accept' => 'application/json', 'Content-Type' => 'application/json', 'Cookie' => $cookieHeader()],
            'body' => json_encode([
                'flow' => $flow['id'],
                'csrf_token' => $csrf,
                'password' => $password,
                'traits' => ['email' => "{$prefix}@e2e.soft.com", 'locale' => 'en', 'display_name' => "SDK PHP {$runId}"],
            ]),
        ]);
        $absorb($reg);
        $body = json_decode((string) $reg->getBody(), true) ?: [];
        $session = $jar['ory_kratos_session'] ?? null;
        $tenantId = $body['session']['identity']['id'] ?? null;
        if ($tenantId === null || $session === null) {
            throw new RuntimeException('registration: HTTP '.$reg->getStatusCode());
        }

        $scopes = explode(',', getenv('SCOPES') ?: 'gate,clockwork,passepartout');
        $key = $mintKey($session, "sdk-php-{$runId}", $scopes);
        $narrow = $mintKey($session, "sdk-php-{$runId}-narrow", ['clockwork']);

        file_put_contents($file, json_encode([
            'runId' => $runId,
            'prefix' => $prefix,
            'baseUrl' => $api,
            'tenantId' => $tenantId,
            'session' => $session,
            'keyId' => $key['id'],
            'key' => $key['raw_key'],
            'narrowKeyId' => $narrow['id'],
            'narrowKey' => $narrow['raw_key'],
            'scopes' => $scopes,
        ]));
        chmod($file, 0600);
        echo "tenant registered: prefix {$prefix}, keys {$key['id']} (".implode(',', $scopes).") and {$narrow['id']} (clockwork)\n";
        break;

    case 'env':
        $s = json_decode((string) file_get_contents($file), true);
        echo "export VERNE_BASE_URL='{$s['baseUrl']}'\n";
        echo "export VERNE_API_KEY='{$s['key']}'\n";
        echo "export VERNE_NARROW_KEY='{$s['narrowKey']}'\n";
        echo "export VERNE_EMAIL_PREFIX='{$s['prefix']}'\n";
        break;

    case 'revoke':
        $s = json_decode((string) file_get_contents($file), true);
        foreach (array_filter([$s['keyId'] ?? null, $s['narrowKeyId'] ?? null]) as $id) {
            $res = $http->patch("{$api}/dashboard/tenant/keys/{$id}", [
                'headers' => ['Cookie' => "ory_kratos_session={$s['session']}"],
            ]);
            echo "revoke {$id}: HTTP {$res->getStatusCode()}\n";
        }
        echo "now sweep: (cd nautilus && DRY_RUN=1 E2E_PREFIXES={$s['prefix']} make purge-e2e, then without DRY_RUN)\n";
        break;

    default:
        fwrite(STDERR, "unknown command {$cmd}\n");
        exit(2);
}
