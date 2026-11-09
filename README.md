# Magento 2 Health Check

A health check endpoint for Magento 2, similar to Laravel's `/up` route. Other modules can add their own checks through an event.

## Install

```
composer require fruitcake/magento2-health-check
php bin/magento module:enable Fruitcake_HealthCheck
php bin/magento setup:upgrade
```

## Usage

```
curl https://example.com/up
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
curl -H 'Authorization: Bearer <key>' https://example.com/up
{
  "status": "warn",
  "checks": {
    "database": [{"status": "pass", "durationMs": 1.2}],
    "cron": [{"status": "warn", "output": "Last cron job finished 42 minutes ago", "durationMs": 0.8, "meta": {"last_finished_at": "2026-11-09T10:18:00+00:00", "minutes_ago": 42}}],
    "disk_space": [{"status": "pass", "durationMs": 0.1, "meta": {"free_percent": 61.3, "free_mb": 48213}}]
  }
}
```

With details, a `warn` or `fail` response also gets a top-level `output` with every check that did not pass, failures first, so a monitor shows the reason without digging through `checks`:

```json
{"status": "fail", "output": "search_engine: Search engine \"elasticsearch8\" is not reachable; cron: Last cron job finished 42 minutes ago", "checks": {...}}
```

Each check holds a list with one check object, as the draft prescribes. `output` holds the message for `warn` and `fail` only. `durationMs` and `meta` are additions of this module.

## Defaults

The module works right after install: `/up` is public, returns only the overall status and runs every check below. No check is strict, so warnings never turn the endpoint red.

## Configuration

Stores > Configuration > Fruitcake Extensions > Health Check:

- **Endpoint**: the path, `up` by default.
- **Key**: when set, requests need an `Authorization: Bearer <key>` header, or `?key=<key>` for monitors that cannot send headers. **Generate Key** fills in a random key. The key is stored as plain text, so it stays readable in the admin. Keys stored encrypted by 0.x still work and are saved as plain text on the next save. Set it from the CLI with `bin/magento config:set fruitcake_healthcheck/general/key <key>`, or in `env.php`.
- **Monitor URL**: the full URL with `?key=` for the default store view, with a **Copy URL** button. It follows the endpoint and key fields as you type, so save before using it.
- **Show Check Details**: adds every check with its status, message, meta data and duration to the response. On by default, but only applies when a key is set.
- **Checks**: per check `No`, `Yes`, `Yes, warnings fail` or `Yes, warnings fail in production mode`. A strict check reports its warnings as failures, so the endpoint returns 503, e.g. when cron is behind. Keep checks non-strict when a load balancer uses the endpoint, or an invalid indexer takes every node out of rotation.
- **Thresholds**: cron age, indexer backlog and free disk space.

The same paths work with `bin/magento config:set`, e.g. `config:set fruitcake_healthcheck/checks/search_engine 0` or `config:set fruitcake_healthcheck/checks/cron strict_production`.

## Checks

| Code | Fails when | Warns when |
|---|---|---|
| `database` | no connection | |
| `cache_storage` | a cache frontend (default, page_cache) cannot write and read back a value | |
| `session_storage` | the session storage Magento uses (files, Redis or database) cannot write and read back a session | no storage test exists for the handler (e.g. memcached), or database sessions without garbage collection (`session.gc_probability = 0`), so the `session` table keeps growing |
| `cache_types` | | a cache type is disabled or invalidated |
| `search_engine` | OpenSearch/Elasticsearch (Magento's own, Amasty Elastic Search or Smile ElasticSuite) does not answer a ping | no connection test exists for the configured engine |
| `indexers` | | an indexer is invalid or its changelog backlog is over the threshold |
| `cron` | | no cron job finished within the threshold (15 minutes). The module adds an index on `cron_schedule` (`status`, `finished_at`) so this stays fast on a large table |
| `deploy_mode` | | not in production mode |
| `debug` | | template hints, debug logging or inline translation is on |
| `disk_space` | free space on `var/` below 2% | free space below 10% |
| `opcache` | | OPcache is off, its status is not available (`opcache.restrict_api`), 90% of its memory or keys (`opcache.max_accelerated_files`) is used, or the hit rate is below 90% after 10,000 requests. The result is per web node |

A check that throws an exception counts as failed, also an observer that does not extend `AbstractCheck`; the checks after it still run. Failed checks are logged, the same failure at most once every 5 minutes.

`session_storage` tests the handler Magento actually uses. Magento only knows `db` and `redis` in `session/save`; another value, e.g. `database`, gives PHP's own handler (usually files), and `meta` then shows both `handler` and `configured`. It tests the handler itself. Magento falls back to file sessions when Redis is down, so the shop seems to work while every node keeps its own sessions and customers get logged out. For file sessions it writes in the save path PHP uses, including the subdirectories of a `N;/path` save path.

## Security

- Without a key, the response is only the overall status (`pass`, `warn` or `fail`) and the HTTP status. Check names, messages, exception texts and versions are never shown.
- With a key set, a request without the right key gets `403` and `Forbidden`, and no check runs.
- Details (including exception messages, which can name hosts or database users) are only shown to requests with the key.
- The key is compared in constant time. It is stored as plain text in `core_config_data`, so anyone with database or config access can read it; it only guards the health details. It is marked sensitive, so `app:config:dump` does not write it to `config.php`.
- `?key=` ends up in access logs. Prefer the `Authorization` header where the monitor supports it. On staging behind HTTP basic auth, the header is taken, so use `?key=` there.
- There is no rate limiting. Use a long random key (the Generate Key button makes 48 hex characters).
- Pick an endpoint no other page uses: the router runs before Magento's own routes.

## Adding a check

Extend `AbstractCheck` and listen to `fruitcake_healthcheck_diagnose`:

```php
use Fruitcake\HealthCheck\Model\CheckResult;
use Fruitcake\HealthCheck\Observer\AbstractCheck;

class Rabbitmq extends AbstractCheck
{
    // Add a constructor with your own dependencies; pass Config on to parent::__construct()


    public function getCode(): string
    {
        return 'rabbitmq';
    }

    protected function check(): CheckResult
    {
        return $this->isConnected()
            ? CheckResult::ok()
            : CheckResult::failed('RabbitMQ is not reachable');
    }
}
```

```xml
<!-- etc/events.xml -->
<event name="fruitcake_healthcheck_diagnose">
    <observer name="vendor_module_rabbitmq" instance="Vendor\Module\Observer\Rabbitmq"/>
</event>
```

```xml
<!-- etc/config.xml: 0, 1, strict or strict_production; the check only runs when it is not 0 -->
<default>
    <fruitcake_healthcheck>
        <checks>
            <rabbitmq>1</rabbitmq>
        </checks>
    </fruitcake_healthcheck>
</default>
```

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

## Tests

```
composer install
composer test
```

Composer gets the Magento packages from the public [Mage-OS mirror](https://mirror.mage-os.org/), so no repo.magento.com credentials are needed. That repository only applies when developing this package; projects installing it use their own.
