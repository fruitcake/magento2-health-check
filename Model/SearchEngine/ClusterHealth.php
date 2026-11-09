<?php

declare(strict_types=1);

namespace Fruitcake\HealthCheck\Model\SearchEngine;

use Magento\Framework\App\Config\ScopeConfigInterface;

/**
 * Reads /_cluster/health of the search engine with the connection settings Magento uses: core OpenSearch and
 * Elasticsearch (catalog/search/<engine>_server_*) and Smile ElasticSuite. A red cluster still answers a ping,
 * but returns broken or empty search results.
 */
class ClusterHealth
{
    private const TIMEOUT = 2;

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig
    ) {
    }

    /**
     * @return array<string, mixed>|null null for an engine it does not know, or when the health cannot be read
     */
    public function get(string $engine): ?array
    {
        foreach ($this->getServers($engine) as $server) {
            $health = $this->request(rtrim($server['url'], '/') . '/_cluster/health', $server['user'], $server['password']);
            if ($health !== null) {
                return $health;
            }
        }

        return null;
    }

    /**
     * @return array<int, array{url: string, user: ?string, password: ?string}>
     */
    private function getServers(string $engine): array
    {
        if ($engine === 'elasticsuite') {
            $prefix = 'smile_elasticsuite_core_base_settings/es_client/';
            $scheme = $this->scopeConfig->isSetFlag($prefix . 'enable_https_mode') ? 'https' : 'http';
            $auth = $this->scopeConfig->isSetFlag($prefix . 'enable_http_auth');
            $servers = [];
            foreach (explode(',', (string) $this->scopeConfig->getValue($prefix . 'servers')) as $server) {
                if (trim($server) !== '') {
                    $servers[] = [
                        'url' => $this->withScheme(trim($server), $scheme),
                        'user' => $auth ? (string) $this->scopeConfig->getValue($prefix . 'http_auth_user') : null,
                        'password' => $auth ? (string) $this->scopeConfig->getValue($prefix . 'http_auth_pwd') : null,
                    ];
                }
            }

            return $servers;
        }

        if (!preg_match('/^(opensearch|elasticsearch\d*)$/', $engine)) {
            return [];
        }

        $prefix = 'catalog/search/' . $engine . '_';
        $host = trim((string) $this->scopeConfig->getValue($prefix . 'server_hostname'));
        if ($host === '') {
            return [];
        }
        $port = trim((string) $this->scopeConfig->getValue($prefix . 'server_port'));
        $auth = $this->scopeConfig->isSetFlag($prefix . 'enable_auth');

        return [[
            'url' => $this->withScheme($host, 'http') . ($port !== '' ? ':' . $port : ''),
            'user' => $auth ? (string) $this->scopeConfig->getValue($prefix . 'username') : null,
            'password' => $auth ? (string) $this->scopeConfig->getValue($prefix . 'password') : null,
        ]];
    }

    private function withScheme(string $host, string $scheme): string
    {
        return preg_match('#^https?://#i', $host) ? $host : $scheme . '://' . $host;
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function request(string $url, ?string $user, ?string $password): ?array
    {
        if (!function_exists('curl_init')) {
            return null;
        }

        $curl = curl_init($url);
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => self::TIMEOUT,
            CURLOPT_TIMEOUT => self::TIMEOUT,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        ]);
        if ($user !== null && $user !== '') {
            curl_setopt($curl, CURLOPT_USERPWD, $user . ':' . $password);
        }
        $body = curl_exec($curl);
        $code = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        curl_close($curl);

        if (!is_string($body) || $code !== 200) {
            return null;
        }
        $health = json_decode($body, true);

        return is_array($health) && isset($health['status']) ? $health : null;
    }
}
