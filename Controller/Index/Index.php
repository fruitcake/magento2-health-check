<?php

declare(strict_types=1);

namespace Fruitcake\HealthCheck\Controller\Index;

use Fruitcake\HealthCheck\Model\Config;
use Fruitcake\HealthCheck\Model\HealthCheck;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\Controller\Result\RawFactory;
use Magento\Framework\Controller\ResultInterface;

/**
 * Responds in the format of draft-inadarei-api-health-check (application/health+json).
 * HTTP 200 for pass and warn, 503 for fail, 403 for a wrong key.
 */
class Index implements HttpGetActionInterface
{
    public function __construct(
        private readonly HttpRequest $request,
        private readonly RawFactory $rawFactory,
        private readonly Config $config,
        private readonly HealthCheck $healthCheck
    ) {
    }

    public function execute(): ResultInterface
    {
        if (!$this->config->isValidKey($this->getGivenKey())) {
            // No status: the health of the application is unknown, not failed
            return $this->respond(403, ['error' => 'Forbidden']);
        }

        $report = $this->healthCheck->run();
        $data = ['status' => $report->getStatus()];
        if ($this->config->showDetails()) {
            // An object, also without checks
            $data['checks'] = $report->toArray() ?: new \stdClass();
        }

        return $this->respond($report->isFailed() ? 503 : 200, $data);
    }

    private function respond(int $code, array $data): ResultInterface
    {
        return $this->rawFactory->create()
            ->setContents(json_encode(
                $data,
                JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR
            ))
            ->setHeader('Content-Type', 'application/health+json', true)
            ->setHeader('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0', true)
            ->setHeader('X-Content-Type-Options', 'nosniff', true)
            ->setHttpResponseCode($code);
    }

    /**
     * Authorization: Bearer <key>, or ?key=<key> for monitors that cannot send headers.
     */
    private function getGivenKey(): string
    {
        $authorization = (string) $this->request->getHeader('Authorization');
        if (preg_match('/^Bearer\s+(\S+)$/i', trim($authorization), $matches)) {
            return $matches[1];
        }

        return (string) $this->request->getParam('key', '');
    }
}
