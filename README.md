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

Each check holds a list with one check object, as the draft prescribes. `output` holds the message for `warn` and `fail` only. `durationMs` and `meta` are additions of this module.

## Defaults

The module works right after install: `/up` is public, returns only the overall status and runs every check below. No check is strict, so warnings never turn the endpoint red.

## Configuration

Stores > Configuration > Fruitcake Extensions > Health Check:

- **Endpoint**: the path, `up` by default.
- **Key**: when set, requests need an `Authorization: Bearer <key>` header, or `?key=<key>` for monitors that cannot send headers. **Generate Key** fills in a random key and shows it, so you can copy it before saving. The key is stored encrypted. Set it from the CLI with `bin/magento config:set fruitcake_healthcheck/general/key <key>`.
- **Show Check Details**: adds every check with its status, message, meta data and duration to the response. On by default, but only applies when a key is set.
- **Checks**: per check `No`, `Yes`, `Yes, warnings fail` or `Yes, warnings fail in production mode`. A strict check reports its warnings as failures, so the endpoint returns 503, e.g. when cron is behind. Keep checks non-strict when a load balancer uses the endpoint, or an invalid indexer takes every node out of rotation.
- **Thresholds**: cron age, indexer backlog and free disk space.

The same paths work with `bin/magento config:set`, e.g. `config:set fruitcake_healthcheck/checks/search_engine 0` or `config:set fruitcake_healthcheck/checks/cron strict_production`.

## Checks

| Code | Fails when | Warns when |
|---|---|---|
| `database` | no connection | |
| `cache` | a cache frontend (default, page_cache) cannot write and read | |
| `cache_types` | | a cache type is disabled or invalidated |
| `search_engine` | OpenSearch/Elasticsearch (Magento's own or Amasty Elastic Search) does not answer a ping | no connection test exists for the configured engine |
| `indexers` | | an indexer is invalid or its changelog backlog is over the threshold |
| `cron` | | no cron job finished within the threshold (15 minutes) |
| `deploy_mode` | | not in production mode |
| `debug` | | template hints, debug logging or inline translation is on |
| `disk_space` | free space on `var/` below 2% | free space below 10% |

A check that throws an exception counts as failed. Failed checks are logged.

## Security

- Without a key, the response is only the overall status (`pass`, `warn` or `fail`) and the HTTP status. Check names, messages, exception texts and versions are never shown.
- With a key set, a request without the right key gets `403` and `Forbidden`, and no check runs.
- Details (including exception messages, which can name hosts or database users) are only shown to requests with the key.
- The key is compared in constant time. A key that cannot be decrypted, e.g. plain text written into `env.php`, is used as is, so the endpoint never falls back to public.
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

The `search_engine` check asks each tester in the pool whether it knows the configured engine. Magento's own OpenSearch/Elasticsearch and Amasty Elastic Search are built in. Add a tester for another engine by implementing `Fruitcake\HealthCheck\Model\SearchEngine\ConnectionTesterInterface` (return `null` for engines it does not know) and adding it in `di.xml`:

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
