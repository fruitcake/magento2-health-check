<?php

declare(strict_types=1);

namespace Fruitcake\HealthCheck\Model;

use Magento\Framework\App\Request\Http as HttpRequest;

/**
 * Tells a person opening the endpoint in a browser apart from a monitor, to show them an HTML page.
 *
 * Browsers send Sec-Fetch-Dest: document when they open a URL. Monitors do not, even the ones that
 * pretend to be a browser (StatusCake sends a Chrome user agent and Accept: text/html). Monitors that
 * use a real browser are recognised by their user agent and always get JSON. Not a security measure:
 * every header can be faked, and the worst a fake one does is change the layout.
 */
class BrowserDetector
{
    /**
     * @param string[] $monitorUserAgents case-insensitive parts of user agents that always get JSON;
     *                                    add more through di.xml, or in the admin
     */
    public function __construct(
        private readonly Config $config,
        private readonly array $monitorUserAgents = []
    ) {
    }

    public function isBrowser(HttpRequest $request): bool
    {
        if (strtolower((string) $request->getHeader('Sec-Fetch-Dest')) !== 'document') {
            return false;
        }

        return !$this->isMonitor((string) $request->getHeader('User-Agent'));
    }

    public function isMonitor(string $userAgent): bool
    {
        $userAgent = strtolower($userAgent);
        foreach (array_merge($this->monitorUserAgents, $this->config->getMonitorUserAgents()) as $monitor) {
            if ($monitor !== '' && str_contains($userAgent, strtolower((string) $monitor))) {
                return true;
            }
        }

        return false;
    }
}
