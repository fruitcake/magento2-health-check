<?php

declare(strict_types=1);

namespace Fruitcake\HealthCheck\Model;

use Magento\Framework\App\ProductMetadataInterface;
use Magento\Framework\App\View\Deployment\Version\StorageInterface as DeployedVersion;

/**
 * The "version", "serviceId" and "releaseId" of draft-inadarei-api-health-check: which Magento version runs, which
 * node answered, and which deploy it runs.
 */
class ServiceInfo
{
    public function __construct(
        private readonly DeployedVersion $deployedVersion,
        private readonly ProductMetadataInterface $productMetadata
    ) {
    }

    /**
     * Magento version and edition, e.g. "2.4.7-p3 (Community)".
     */
    public function getVersion(): string
    {
        try {
            $version = (string) $this->productMetadata->getVersion();
            $edition = (string) $this->productMetadata->getEdition();
        } catch (\Throwable) {
            return '';
        }
        if ($version === '' || $version === 'UNKNOWN') {
            return '';
        }

        return $edition !== '' ? sprintf('%s (%s)', $version, $edition) : $version;
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
