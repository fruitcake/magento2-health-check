<?php

declare(strict_types=1);

namespace Fruitcake\HealthCheck\Controller\Index;

use Fruitcake\HealthCheck\Model\Config;
use Fruitcake\HealthCheck\Model\HealthCheck;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Controller\Result\RawFactory;
use Magento\Framework\Controller\ResultInterface;

/**
 * Returns JSON when the client sends Accept: application/json, plain text otherwise.
 * HTTP 200 when no check failed, 503 when one did, 403 for a wrong key.
 */
class Index implements HttpGetActionInterface
{
    public function __construct(
        private readonly HttpRequest $request,
        private readonly JsonFactory $jsonFactory,
        private readonly RawFactory $rawFactory,
        private readonly Config $config,
        private readonly HealthCheck $healthCheck
    ) {
    }

    public function execute(): ResultInterface
    {
        if (!$this->config->isValidKey($this->getGivenKey())) {
            return $this->respond(403, ['status' => 'forbidden']);
        }

        $report = $this->healthCheck->run();
        $data = ['status' => $report->getStatus()];
        if ($this->config->showDetails()) {
            $data['checks'] = $report->toArray();
        }

        return $this->respond($report->isFailed() ? 503 : 200, $data);
    }

    private function respond(int $code, array $data): ResultInterface
    {
        if ($this->wantsJson()) {
            $result = $this->jsonFactory->create()->setData($data);
        } else {
            $result = $this->rawFactory->create()
                ->setHeader('Content-Type', 'text/plain; charset=utf-8', true)
                ->setContents($this->toText($data));
        }

        return $result
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

    private function wantsJson(): bool
    {
        $accept = (string) $this->request->getHeader('Accept');

        return str_contains($accept, '/json') || str_contains($accept, '+json');
    }

    private function toText(array $data): string
    {
        $lines = [strtoupper($data['status'])];
        foreach ($data['checks'] ?? [] as $code => $check) {
            $line = sprintf('%s: %s', $code, $check['status']);
            if (!empty($check['message'])) {
                $line .= ' - ' . $check['message'];
            }
            $lines[] = $line;
        }

        return implode("\n", $lines) . "\n";
    }
}
