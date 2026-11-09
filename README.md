# Magento 2 Health Check

A health check endpoint for Magento 2 at `/health`, similar to Laravel's `/up` route. Other modules can add their own checks through an event.

<img width="2024" height="1660" alt="Screenshot 2026-11-09 at 14 00 47@2x" src="https://github.com/user-attachments/assets/8ca018f9-e369-4130-9b5d-a4bf7451cda9" />


## Install

```
composer require fruitcake/magento2-health-check
php bin/magento module:enable Fruitcake_HealthCheck
php bin/magento setup:upgrade
```

## Usage

```
curl https://example.com/health
{"status":"pass"}
```

| Status | HTTP |
|---|---|
| `pass` | 200 |
| `warn` | 200 |
| `fail` | 503 |
| wrong key | 403, `{"error":"Forbidden"}` |

The response is JSON in the format of [draft-inadarei-api-health-check](https://datatracker.ietf.org/doc/html/draft-inadarei-api-health-check-06), sent as `application/health+json`. It is never cached.

With a key set, the response lists every check:

```
curl -H 'Authorization: Bearer <key>' https://example.com/health
{
  "status": "warn",
  "output": "cron: Last cron job finished 42 minutes ago",
  "version": "2.4.7-p3 (Community)",
  "serviceId": "web1",
  "releaseId": "1731151234",
  "checks": {
    "database": [{"status": "pass"}],
    "database:responseTime": [{"observedValue": 1.2, "observedUnit": "ms", "status": "pass"}],
    "cron": [{"status": "warn", "output": "Last cron job finished 42 minutes ago", "meta": {"last_finished_at": "2026-11-09T10:18:00+00:00"}}],
    "cron:lastFinished": [{"observedValue": 42, "observedUnit": "min", "status": "warn"}],
    "disk_space": [{"status": "pass"}],
    "disk_space:free": [{"observedValue": 61.3, "observedUnit": "percent", "status": "pass"}],
    "disk_space:freeSpace": [{"observedValue": 48213, "observedUnit": "MiB", "status": "pass"}]
  }
}
```

With details, the response also has `version`, the Magento version and edition, `serviceId`, the hostname of the node that answered, and `releaseId`, the static content version from `pub/static/deployed_version.txt`. `setup:static-content:deploy` writes that file on every deploy, whatever the deploy tool, so after a deploy you can see whether every node runs the same release. `releaseId` is left out when the file does not exist, e.g. in developer mode.

With details, a `warn` or `fail` response also gets a top-level `output` with every check that did not pass, failures first, so a monitor shows the reason without digging through `checks`:

```json
{"status": "fail", "output": "search_engine: Search engine \"elasticsearch8\" is not reachable; cron: Last cron job finished 42 minutes ago", "checks": {...}}
```

Each check holds a list with one check object, as the draft prescribes, followed by its measurements as `<check>:<measurement>` with `observedValue` and `observedUnit`. `output` holds the message for `warn` and `fail` only. Checks that talk to a service (database, cache and session storage, search engine, RabbitMQ) add `<check>:responseTime` in ms. `meta` is an addition of this module.

### Oh Dear

The endpoint also speaks the format of [Oh Dear's Application Health monitoring](https://ohdear.app/docs/features/application-health-monitoring). In Oh Dear, set the health check URL to the endpoint and the secret to the key. Oh Dear sends the key in the `oh-dear-health-check-secret` header, which gets that format; `?format=ohdear` does the same for testing. The response is always HTTP 200, as the status of each check is in the body, and `403` for a wrong secret. Every check becomes a check result; nested `meta` values are joined into one string, as Oh Dear shows meta as keys and values. Without details, the only result is `health` with the overall status.

### In a browser

Opened in a browser, the endpoint shows an HTML page: the status, a table of the checks, and the JSON. Browsers are recognised by the `Sec-Fetch-Dest: document` header, which monitors do not send, even the ones that pretend to be a browser, like StatusCake. Monitors that use a real browser get JSON by their user agent: StatusCake, UptimeRobot, Pingdom, Oh Dear, Checkly, Better Stack and others are built in, and **Monitor User Agents** in the configuration adds more. `?format=json` or `?format=html` overrides the detection. The HTTP status is the same, and the page holds the JSON too, so a keyword check on e.g. `"pass"` still matches.

## Defaults

The module works right after install: `/health` is public, returns only the overall status and runs every check below. No check is strict, so warnings never turn the endpoint red.

## Configuration

Stores > Configuration > Fruitcake Extensions > Health Check:

- **Endpoint**: the path, `health` by default. Before 1.0 the default was `up`: shops that relied on it can keep it with `bin/magento config:set fruitcake_healthcheck/general/endpoint up`.
- **Key**: when set, requests need an `Authorization: Bearer <key>` header, or `?key=<key>` for monitors that cannot send headers. **Generate Key** fills in a random key. The key is stored as plain text, so it stays readable in the admin. Keys stored encrypted by 0.x still work and are saved as plain text on the next save. Set it from the CLI with `bin/magento config:set fruitcake_healthcheck/general/key <key>`, or in `env.php`.
- **Monitor URL**: the full URL with `?key=` for the default store view, with a **Copy URL** button. It follows the endpoint and key fields as you type, so save before using it.
- **Monitor User Agents**: parts of user agents, one per line, that always get JSON instead of the HTML page for browsers.
- **Show Check Details**: adds every check with its status, message, meta data and measurements to the response. On by default, but only applies when a key is set.
- **Checks**: per check `No`, `Yes`, `Yes, warnings fail` or `Yes, warnings fail in production mode`. A strict check reports its warnings as failures, so the endpoint returns 503, e.g. when cron is behind. Keep checks non-strict when a load balancer uses the endpoint, or an invalid indexer takes every node out of rotation.
- **Thresholds**: cron age, indexer backlog, MySQL queue backlog and free disk space.

The same paths work with `bin/magento config:set`, e.g. `config:set fruitcake_healthcheck/checks/search_engine 0` or `config:set fruitcake_healthcheck/checks/cron strict_production`.

## Checks

| Code | Fails when | Warns when |
|---|---|---|
| `database` | no connection | 80% of `max_connections` is in use. Measures `connections` and `connectionsUsed` |
| `cache_storage` | a cache frontend (default, page_cache) cannot write and read back a value. For Redis frontends it measures memory use and evicted keys | |
| `session_storage` | the session storage Magento uses (files, Redis or database) cannot write and read back a session | no storage test exists for the handler (e.g. memcached), or database sessions without garbage collection (`session.gc_probability = 0`), so the `session` table keeps growing, or the session Redis evicted keys, which logs customers out |
| `cache_types` | | a cache type is disabled or invalidated |
| `search_engine` | OpenSearch/Elasticsearch (Magento's own, Amasty Elastic Search or Smile ElasticSuite) does not answer a ping, or its cluster health is red (primary shards missing) | no connection test exists for the configured engine, or the cluster health is yellow on more than one data node |
| `rabbitmq` | no connection to RabbitMQ with `queue/amqp` from `env.php` (passes without connecting when it is not configured) | |
| `mysql_queue` | | 1000 or more messages wait in Magento's MySQL queues (`Magento_MysqlMq`), i.e. a consumer does not run. Measures `waiting`, `retry` and `failed` |
| `indexers` | | an indexer is invalid or its changelog backlog is over the threshold |
| `cron` | | no cron job finished within the threshold (15 minutes). The module adds an index on `cron_schedule` (`status`, `finished_at`) so this stays fast on a large table |
| `deploy_mode` | | not in production mode, or the Composer autoloader is not optimized (`composer dump-autoload --optimize`) |
| `debug` | | template hints, debug logging, inline translation, a database profiler or `static_content_on_demand_in_production` is on |
| `public_files` | | `pub/` or `pub/media/` holds database dumps, archives, `phpinfo.php`, `.git`, `.env`, `composer.json` or `auth.json` |
| `disk_space` | free space on `var/` below 2% | free space below 10% |
| `opcache` | | OPcache is off, its status is not available (`opcache.restrict_api`), 90% of its memory or keys (`opcache.max_accelerated_files`) is used, or the hit rate is below 90% after 10,000 requests. The result is per web node |
| `two_factor_auth` | | admin two-factor authentication is off: `Magento_TwoFactorAuth` is disabled, or `twofactorauth/general/enable` is 0 (e.g. MarkShust_DisableTwoFactorAuth). Use `Yes, warnings fail in production mode` to fail on production only |

A check that throws an exception counts as failed, also an observer that does not extend `AbstractCheck`; the checks after it still run. Failed checks are logged, the same failure at most once every 5 minutes.

`session_storage` tests the handler Magento actually uses. Magento only knows `db` and `redis` in `session/save`; another value, e.g. `database`, gives PHP's own handler (usually files), and `meta` then shows both `handler` and `configured`. It tests the handler itself. Magento falls back to file sessions when Redis is down, so the shop seems to work while every node keeps its own sessions and customers get logged out. For file sessions it writes in the save path PHP uses, including the subdirectories of a `N;/path` save path.

## Security

- Without a key, the response is only the overall status (`pass`, `warn` or `fail`) and the HTTP status. Check names, messages, exception texts and versions are never shown.
- With a key set, a request without the right key gets `403` and `Forbidden`, and no check runs.
- Details (including exception messages, which can name hosts or database users) are only shown to requests with the key. Credentials in exception messages, like `user:password@host` or `password=...`, are masked before they reach the response or the log.
- The key is compared in constant time. It is stored as plain text in `core_config_data`, so anyone with database or config access can read it; it only guards the health details. It is marked sensitive, so `app:config:dump` does not write it to `config.php`.
- `?key=` ends up in access logs. Prefer the `Authorization` header where the monitor supports it. On staging behind HTTP basic auth, the header is taken, so use `?key=` there.
- There is no rate limiting. Use a long random key (the Generate Key button makes 48 hex characters).
- Pick an endpoint no other page uses: the router runs before Magento's own routes.

## Adding a check

Extend `AbstractCheck` and listen to `fruitcake_healthcheck_diagnose`:

```php
use Fruitcake\HealthCheck\Model\CheckResult;
use Fruitcake\HealthCheck\Observer\AbstractCheck;

class Erp extends AbstractCheck
{
    // Add a constructor with your own dependencies; pass Config on to parent::__construct()


    public function getCode(): string
    {
        return 'erp';
    }

    protected function check(): CheckResult
    {
        return $this->isConnected()
            ? CheckResult::ok()
            : CheckResult::failed('ERP is not reachable');
    }
}
```

```xml
<!-- etc/events.xml -->
<event name="fruitcake_healthcheck_diagnose">
    <observer name="vendor_module_erp" instance="Vendor\Module\Observer\Erp"/>
</event>
```

```xml
<!-- etc/config.xml: 0, 1, strict or strict_production; the check only runs when it is not 0 -->
<default>
    <fruitcake_healthcheck>
        <checks>
            <erp>1</erp>
        </checks>
    </fruitcake_healthcheck>
</default>
```

Report measured values as measurements, not in `meta`: `CheckResult::ok()->withMeasurement('hitRate', 99.5, 'percent')`. They appear as `"<code>:<name>"` with `observedValue` and `observedUnit`, as draft-inadarei-api-health-check prescribes, e.g. `"opcache:hitRate": [{"observedValue": 99.5, "observedUnit": "percent", "status": "pass"}]`, with the status of the check. Use `meta` for the rest, like names and modes. A check that talks to a service can override `measuresResponseTime()` to return `true`, which adds `<code>:responseTime`.

To make it configurable in the admin, add a field to the `fruitcake_healthcheck/checks` group in your own `system.xml`, with `Fruitcake\HealthCheck\Model\Config\Source\CheckMode` as source model.

### Search engines

The `search_engine` check asks each tester in the pool whether it knows the configured engine. Magento's own OpenSearch/Elasticsearch, Amasty Elastic Search and Smile ElasticSuite are built in. Add a tester for another engine by implementing `Fruitcake\HealthCheck\Model\SearchEngine\ConnectionTesterInterface` (return `null` for engines it does not know) and adding it in `di.xml`:

```xml
<type name="Fruitcake\HealthCheck\Observer\Check\SearchEngine">
    <arguments>
        <argument name="testers" xsi:type="array">
            <item name="mirasvit" xsi:type="object">Vendor\Module\Model\MirasvitConnectionTester</item>
        </argument>
    </arguments>
</type>
```

### Without AbstractCheck

An observer that does not extend `AbstractCheck` gets the report with `$observer->getData('report')` and calls `$report->add('code', CheckResult::warning('...'))`.

## Adding a format

The response formats (`json`, `ohdear`, `html`) are a pool in `di.xml`. Add one by implementing `Fruitcake\HealthCheck\Model\Format\FormatInterface`:

- `getLabel()`: the name shown in the admin, e.g. in the format choice of the monitor URL.
- `matches($request)`: whether the format suits a request without `?format=`, e.g. by a header. Return `false` to only use it with `?format=`.
- `render($report, $details)`: a `FormatResult` with the body, content type and HTTP status. With `$details` false, show only the overall status, as for requests without the key.

```xml
<type name="Fruitcake\HealthCheck\Model\Format\FormatPool">
    <arguments>
        <argument name="formats" xsi:type="array">
            <item name="prometheus" xsi:type="object">Vendor\Module\Model\PrometheusFormat</item>
        </argument>
    </arguments>
</type>
```

`?format=prometheus` then picks it. Without `?format=`, the first format whose `matches()` is true wins, in the order of the pool, and `json` is the default. The key is checked before any format runs; a wrong key always gets `403` JSON.

## Tests

```
composer install
composer test
```

Composer gets the Magento packages from the public [Mage-OS mirror](https://mirror.mage-os.org/), so no repo.magento.com credentials are needed. That repository only applies when developing this package; projects installing it use their own.
