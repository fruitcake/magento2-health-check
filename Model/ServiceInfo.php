<?php

declare(strict_types=1);

namespace Fruitcake\HealthCheck\Model;

use Magento\Framework\App\View\Deployment\Version\StorageInterface as DeployedVersion;

/**
 * The "serviceId" and "releaseId" of draft-inadarei-api-health-check: which node answered, and which deploy it runs.
 */
class ServiceInfo
{
    public function __construct(
        private readonly DeployedVersion $deployedVersion
    ) {
    }

    /**
     * Hostname of the node, so behind a load balancer it is clear which node answered.
     */
    public function getServiceId(): string
    {
        return (string) gethostname();
    }

    /**
     * Version from pub/static/deployed_version.txt, which setup:static-content:deploy writes on every deploy,
     * whatever the deploy tool. Empty when the file does not exist, e.g. in developer mode.
     */
    public function getReleaseId(): string
    {
        try {
            return trim((string) $this->deployedVersion->load());
        } catch (\Throwable) {
            return '';
        }
    }
}
