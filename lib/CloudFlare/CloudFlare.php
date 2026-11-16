<?php
namespace Zero\Lib\CloudFlare;

use Zero\Lib\Http\Client;
use Zero\Core\Console;

/**
 * CloudFlare API integration for IP access rules
 * Uses the Cloudflare WAF access rules API to block malicious IPs
 */
class CloudFlare {

    private const API_BASE = 'https://api.cloudflare.com/client/v4';
    private const CONFIG_FILE = __DIR__ . '/../../../app/config/cloudflare.ini';

    /**
     * Block an IP address via Cloudflare access rules
     *
     * @param string $ip The IP address to block
     * @param string $reason Optional reason for the block
     * @return bool Whether the block was successful
     */
    public static function block(string $ip, string $reason = 'Blocked by Zero Tolerance'): bool {
        $config = self::loadConfig();
        if (!$config) {
            Console::ban("CloudFlare block failed for {$ip} — missing cloudflare.ini configuration");
            return false;
        }

        $client = self::buildClient($config);
        $url = self::API_BASE . '/accounts/' . $config['CLOUDFLARE_ACCOUNT_ID'] . '/firewall/access_rules/rules';

        $response = $client->post($url, [
            'mode' => 'block',
            'configuration' => [
                'target' => 'ip',
                'value' => $ip,
            ],
            'notes' => $reason,
        ]);

        if ($response->isSuccess()) {
            Console::ban("CloudFlare blocked {$ip} ({$reason})");
            return true;
        }

        $error = $response->json()['errors'][0]['message'] ?? "HTTP {$response->statusCode}";
        Console::ban("CloudFlare block failed for {$ip} — API error: {$error} ({$reason})");
        return false;
    }

    /**
     * Unblock an IP address by finding and deleting its access rule
     *
     * @param string $ip The IP address to unblock
     * @return bool Whether the unblock was successful
     */
    public static function unblock(string $ip): bool {
        $config = self::loadConfig();
        if (!$config) {
            Console::ban("CloudFlare unblock failed for {$ip} — missing cloudflare.ini configuration");
            return false;
        }

        // Find the rule ID for this IP
        $data = self::list(['configuration.value' => $ip, 'mode' => 'block']);
        if ($data === null) {
            Console::ban("CloudFlare unblock failed for {$ip} — could not retrieve rules");
            return false;
        }

        if (empty($data['result'])) {
            Console::ban("CloudFlare unblock failed for {$ip} — no matching rule found");
            return false;
        }

        $ruleId = $data['result'][0]['id'];
        $client = self::buildClient($config);
        $url = self::API_BASE . '/accounts/' . $config['CLOUDFLARE_ACCOUNT_ID'] . '/firewall/access_rules/rules/' . $ruleId;

        $response = $client->delete($url);

        if ($response->isSuccess()) {
            Console::ban("CloudFlare unblocked {$ip} (rule {$ruleId})");
            return true;
        }

        $error = $response->json()['errors'][0]['message'] ?? "HTTP {$response->statusCode}";
        Console::ban("CloudFlare unblock failed for {$ip} — API error: {$error}");
        return false;
    }

    /**
     * List IP access rules from Cloudflare
     *
     * @param array $filters Optional filters (mode, configuration.value, notes, etc.)
     * @param int $page Page number
     * @param int $perPage Results per page (max 100)
     * @return array|null Full response with 'result' and 'result_info' keys, null on failure
     */
    public static function list(array $filters = [], int $page = 1, int $perPage = 50): ?array {
        $config = self::loadConfig();
        if (!$config) {
            return null;
        }

        $client = self::buildClient($config);
        $url = self::API_BASE . '/accounts/' . $config['CLOUDFLARE_ACCOUNT_ID'] . '/firewall/access_rules/rules';

        $params = array_merge($filters, [
            'page' => $page,
            'per_page' => $perPage,
        ]);

        $client->setHeader('Content-Type', 'application/json');
        $response = $client->get($url, $params);

        if ($response->isSuccess()) {
            $data = $response->json();
            return [
                'result' => $data['result'] ?? [],
                'result_info' => $data['result_info'] ?? [],
            ];
        }

        Console::error("CloudFlare list failed — HTTP {$response->statusCode}, error: {$response->error}, body: " . substr($response->body ?? '', 0, 500));
        return null;
    }

    /**
     * Build an authenticated HTTP client
     */
    private static function buildClient(array $config): Client {
        $client = new Client();
        $client->setHeader('X-Auth-Key', $config['CLOUDFLARE_API_KEY']);
        $client->setHeader('X-Auth-Email', $config['CLOUDFLARE_EMAIL']);
        return $client;
    }

    /**
     * Load Cloudflare configuration from ini file
     */
    private static function loadConfig(): ?array {
        $path = self::CONFIG_FILE;
        if (!file_exists($path)) {
            return null;
        }

        $config = parse_ini_file($path);
        if (empty($config['CLOUDFLARE_API_KEY']) || empty($config['CLOUDFLARE_EMAIL']) || empty($config['CLOUDFLARE_ACCOUNT_ID'])) {
            return null;
        }

        return $config;
    }
}
