<?php

declare(strict_types=1);

namespace Fruitcake\HealthCheck\Controller\Index;

use Fruitcake\HealthCheck\Model\Config;
use Fruitcake\HealthCheck\Model\Format\FormatPool;
use Fruitcake\HealthCheck\Model\Format\HealthJson;
use Fruitcake\HealthCheck\Model\Format\OhDear;
use Fruitcake\HealthCheck\Model\HealthCheck;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\Controller\Result\RawFactory;
use Magento\Framework\Controller\ResultInterface;

/**
 * Checks the key, runs the checks and responds in the format the FormatPool picks: health+json by default,
 * Oh Dear's format for Oh Dear, an HTML page in a browser, or ?format=<name>. A wrong key gets 403.
 */
class Index implements HttpGetActionInterface
{
    public function __construct(
        private readonly HttpRequest $request,
        private readonly RawFactory $rawFactory,
        private readonly Config $config,
        private readonly HealthCheck $healthCheck,
        private readonly FormatPool $formatPool
    ) {
    }

    public function execute(): ResultInterface
    {
        if (!$this->config->isValidKey($this->getGivenKey())) {
            // JSON in every format, without a status: the health of the application is unknown, not failed
            return $this->respond(403, json_encode(['error' => 'Forbidden'], HealthJson::JSON_FLAGS), 'application/json');
        }

        $result = $this->formatPool->select($this->request)
            ->render($this->healthCheck->run(), $this->config->showDetails());

        return $this->respond($result->httpCode, $result->body, $result->contentType, $result->headers);
    }

    /**
     * @param array<string, string> $headers
     */
    private function respond(int $code, string $body, string $contentType, array $headers = []): ResultInterface
    {
        $result = $this->rawFactory->create()
            ->setContents($body)
            ->setHeader('Content-Type', $contentType, true)
            ->setHeader('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0', true)
            ->setHeader('X-Content-Type-Options', 'nosniff', true)
            // The format depends on the client, so a cache in between must not mix them up
            ->setHeader('Vary', 'Sec-Fetch-Dest, User-Agent, ' . OhDear::SECRET_HEADER, true);
        foreach ($headers as $name => $value) {
            $result->setHeader($name, $value, true);
        }

        return $result->setHttpResponseCode($code);
    }

    /**
     * Authorization: Bearer <key>, the oh-dear-health-check-secret header Oh Dear sends,
     * or ?key=<key> for monitors that cannot send headers.
     */
    private function getGivenKey(): string
    {
        $ohDearSecret = $this->request->getHeader(OhDear::SECRET_HEADER);
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
