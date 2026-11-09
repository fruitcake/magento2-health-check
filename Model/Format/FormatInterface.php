<?php

declare(strict_types=1);

namespace Fruitcake\HealthCheck\Model\Format;

use Fruitcake\HealthCheck\Model\HealthReport;
use Magento\Framework\App\Request\Http as HttpRequest;

/**
 * A response format of the endpoint. Add one to the "formats" argument of FormatPool in di.xml;
 * ?format=<name> picks it, or matches() when no format is given.
 */
interface FormatInterface
{
    /**
     * Shown in the admin, e.g. in the format choice of the monitor URL.
     */
    public function getLabel(): string;

    /**
     * Whether this format suits the request without ?format=, e.g. by a header. The pool asks the formats in order.
     */
    public function matches(HttpRequest $request): bool;

    /**
     * @param bool $details false: only the overall status, as for requests without the key
     */
    public function render(HealthReport $report, bool $details): FormatResult;
}
