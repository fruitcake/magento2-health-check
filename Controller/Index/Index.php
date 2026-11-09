<?php

declare(strict_types=1);

namespace Fruitcake\HealthCheck\Controller\Index;

use Fruitcake\HealthCheck\Model\BrowserDetector;
use Fruitcake\HealthCheck\Model\Config;
use Fruitcake\HealthCheck\Model\Format\Html;
use Fruitcake\HealthCheck\Model\HealthCheck;
use Fruitcake\HealthCheck\Model\ServiceInfo;
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
        private readonly HealthCheck $healthCheck,
        private readonly ServiceInfo $serviceInfo,
        private readonly BrowserDetector $browserDetector,
        private readonly Html $htmlFormat
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
            $output = $report->getOutput();
            if ($output !== '') {
                $data['output'] = $output;
            }
            // Which node answered and which deploy it runs; both say something about the infrastructure
            $data += array_filter([
                'serviceId' => $this->serviceInfo->getServiceId(),
                'releaseId' => $this->serviceInfo->getReleaseId(),
            ], static fn (string $value) => $value !== '');
            // An object, also without checks
            $data['checks'] = $report->toArray() ?: new \stdClass();
        }

        return $this->respond($report->isFailed() ? 503 : 200, $data);
    }

    private function respond(int $code, array $data): ResultInterface
    {
        $flags = JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR;
        $result = $this->rawFactory->create();

        if ($this->wantsHtml()) {
            $result->setContents($this->htmlFormat->render($data, json_encode($data, $flags | JSON_PRETTY_PRINT)))
                ->setHeader('Content-Type', 'text/html; charset=utf-8', true)
                // Inline styles only: no scripts, no requests elsewhere, not in a frame
                ->setHeader(
                    'Content-Security-Policy',
                    "default-src 'none'; style-src 'unsafe-inline'; frame-ancestors 'none'; base-uri 'none'; form-action 'none'",
                    true
                );
        } else {
            $result->setContents(json_encode($data, $flags))
                ->setHeader('Content-Type', 'application/health+json', true);
        }

        return $result
            ->setHeader('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0', true)
            ->setHeader('X-Content-Type-Options', 'nosniff', true)
            // The page differs per client, so a cache in between must not mix them up
            ->setHeader('Vary', 'Sec-Fetch-Dest, User-Agent', true)
            ->setHttpResponseCode($code);
    }

    /**
     * HTML for a person opening the endpoint in a browser, JSON for monitors. ?format=json or ?format=html decides.
     */
    private function wantsHtml(): bool
    {
        $format = $this->request->getParam('format');
        if ($format === 'json' || $format === 'html') {
            return $format === 'html';
        }

        return $this->browserDetector->isBrowser($this->request);
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

        $key = $this->request->getParam('key', '');

        // ?key[]=x gives an array
        return is_string($key) ? $key : '';
    }
}
