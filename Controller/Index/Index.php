<?php

declare(strict_types=1);

namespace Fruitcake\HealthCheck\Controller\Index;

use Fruitcake\HealthCheck\Model\Config;
use Fruitcake\HealthCheck\Model\Format\OhDear;
use Fruitcake\HealthCheck\Model\HealthCheck;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\Controller\Result\RawFactory;
use Magento\Framework\Controller\ResultInterface;

/**
 * Responds in the format of draft-inadarei-api-health-check (application/health+json):
 * HTTP 200 for pass and warn, 503 for fail, 403 for a wrong key.
 *
 * Requests from Oh Dear (the oh-dear-health-check-secret header) or with ?format=ohdear get the format of
 * Oh Dear's Application Health monitoring instead, always with HTTP 200: the status is in the body.
 */
class Index implements HttpGetActionInterface
{
    private const OH_DEAR_HEADER = 'oh-dear-health-check-secret';

    public function __construct(
        private readonly HttpRequest $request,
        private readonly RawFactory $rawFactory,
        private readonly Config $config,
        private readonly HealthCheck $healthCheck,
        private readonly OhDear $ohDearFormat
    ) {
    }

    public function execute(): ResultInterface
    {
        if (!$this->config->isValidKey($this->getGivenKey())) {
            // No status: the health of the application is unknown, not failed
            return $this->respond(403, ['error' => 'Forbidden']);
        }

        $report = $this->healthCheck->run();
        if ($this->wantsOhDear()) {
            return $this->respond(200, $this->ohDearFormat->toArray($report, $this->config->showDetails()), 'application/json');
        }

        $data = ['status' => $report->getStatus()];
        if ($this->config->showDetails()) {
            $output = $report->getOutput();
            if ($output !== '') {
                $data['output'] = $output;
            }
            // An object, also without checks
            $data['checks'] = $report->toArray() ?: new \stdClass();
        }

        return $this->respond($report->isFailed() ? 503 : 200, $data);
    }

    private function respond(int $code, array $data, string $contentType = 'application/health+json'): ResultInterface
    {
        return $this->rawFactory->create()
            ->setContents(json_encode(
                $data,
                JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR
            ))
            ->setHeader('Content-Type', $contentType, true)
            ->setHeader('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0', true)
            ->setHeader('X-Content-Type-Options', 'nosniff', true)
            ->setHttpResponseCode($code);
    }

    private function wantsOhDear(): bool
    {
        return $this->request->getHeader(self::OH_DEAR_HEADER) !== false
            || $this->request->getParam('format') === 'ohdear';
    }

    /**
     * Authorization: Bearer <key>, the oh-dear-health-check-secret header Oh Dear sends,
     * or ?key=<key> for monitors that cannot send headers.
     */
    private function getGivenKey(): string
    {
        $ohDearSecret = $this->request->getHeader(self::OH_DEAR_HEADER);
        if (is_string($ohDearSecret) && $ohDearSecret !== '') {
            return trim($ohDearSecret);
        }

        $authorization = (string) $this->request->getHeader('Authorization');
        if (preg_match('/^Bearer\s+(\S+)$/i', trim($authorization), $matches)) {
            return $matches[1];
        }

        $key = $this->request->getParam('key', '');

        // ?key[]=x gives an array
        return is_string($key) ? $key : '';
    }
}
