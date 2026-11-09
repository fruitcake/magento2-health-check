<?php

declare(strict_types=1);

namespace Fruitcake\HealthCheck\Model\Format;

use Fruitcake\HealthCheck\Model\BrowserDetector;
use Fruitcake\HealthCheck\Model\HealthReport;
use Magento\Framework\App\Request\Http as HttpRequest;

/**
 * A page for people who open the endpoint in a browser: the status, a table of the checks, and the
 * JSON itself, so a keyword check on e.g. "pass" still matches if a monitor ever gets this page.
 * The same data and HTTP status as the health+json format. Every value is escaped: messages can
 * hold exception texts.
 */
class Html implements FormatInterface
{
    // Inline styles only: no scripts, no requests elsewhere, not in a frame
    private const CSP = "default-src 'none'; style-src 'unsafe-inline'; frame-ancestors 'none'; base-uri 'none'; form-action 'none'";

    public function __construct(
        private readonly HealthJson $healthJson,
        private readonly BrowserDetector $browserDetector
    ) {
    }

    public function getLabel(): string
    {
        return 'HTML';
    }

    public function matches(HttpRequest $request): bool
    {
        return $this->browserDetector->isBrowser($request);
    }

    public function render(HealthReport $report, bool $details): FormatResult
    {
        $data = $this->healthJson->toArray($report, $details);

        return new FormatResult(
            $this->renderPage($data, json_encode($data, HealthJson::JSON_FLAGS | JSON_PRETTY_PRINT)),
            'text/html; charset=utf-8',
            $this->healthJson->getHttpCode($report),
            ['Content-Security-Policy' => self::CSP]
        );
    }

    public function renderPage(array $data, string $json): string
    {
        $status = (string) ($data['status'] ?? '');
        // Measurements ("opcache:hitRate") are shown with their check, not as rows of their own
        $all = (array) ($data['checks'] ?? []);
        $checks = [];
        $measurements = [];
        foreach ($all as $key => $results) {
            [$code, $name] = array_pad(explode(':', (string) $key, 2), 2, null);
            if ($name !== null && isset($all[$code])) {
                $measurements[$code][$name] = (array) ($results[0] ?? []);
            } else {
                $checks[$key] = $results;
            }
        }
        $checks = $this->sortProblemsFirst($checks);

        $counts = ['fail' => 0, 'warn' => 0, 'pass' => 0];
        $totalMs = 0.0;
        $rows = '';
        $problems = [];
        foreach ($checks as $code => $results) {
            foreach ((array) $results as $result) {
                $checkStatus = (string) ($result['status'] ?? '');
                $counts[$checkStatus] = ($counts[$checkStatus] ?? 0) + 1;
                $totalMs += (float) ($measurements[$code]['responseTime']['observedValue'] ?? 0);
                $rows .= $this->renderRow((string) $code, $result, $measurements[$code] ?? []);
                if ($checkStatus === $status && $status !== 'pass') {
                    $problems[] = [(string) $code, (string) ($result['output'] ?? '')];
                }
            }
        }
        $total = array_sum($counts);

        $facts = array_filter([
            isset($data['version']) ? ['Magento', (string) $data['version'], false] : null,
            isset($data['releaseId']) ? ['Release', (string) $data['releaseId'], true] : null,
            ['Checked', gmdate('Y-m-d H:i') . ' UTC', false],
            $total ? ['Checks', (string) $total, false] : null,
        ]);
        $factsHtml = '';
        foreach ($facts as [$label, $value, $mono]) {
            $factsHtml .= '<div><dt>' . $this->escape($label) . '</dt><dd' . ($mono ? ' class="mono"' : '') . '>'
                . $this->escape($value) . '</dd></div>';
        }

        $body = '<header class="head"><p class="eyebrow">Magento health check</p>'
            . '<h1>' . $this->escape((string) ($data['serviceId'] ?? 'Health')) . '</h1>'
            . '<dl class="facts">' . $factsHtml . '</dl></header>'
            . $this->renderBanner($status, $counts, $total, $problems);

        if ($total) {
            $body .= '<section class="stats">'
                . $this->renderStat('fail', (string) $counts['fail'], 'Fail')
                . $this->renderStat('warn', (string) $counts['warn'], 'Warn')
                . $this->renderStat('pass', (string) $counts['pass'], 'Pass')
                . $this->renderStat('time', $this->formatDuration($totalMs), 'Total response time')
                . '</section>'
                . '<section class="card scroll"><table>'
                . '<colgroup><col class="c-check"><col class="c-status"><col><col class="c-time"></colgroup>'
                . '<thead><tr><th>Check</th><th>Status</th><th>Details</th><th class="num">Response time</th></tr></thead>'
                . '<tbody>' . $rows . '</tbody></table></section>';
        } else {
            $body .= '<p class="muted">Checks are only listed with the key, when Show Check Details is on.</p>';
        }

        return '<!doctype html><html lang="en"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<meta name="robots" content="noindex, nofollow">'
            . '<title>' . $this->escape(ucfirst($status) . ' · ' . ($data['serviceId'] ?? 'Health')) . '</title>'
            . '<style>' . self::CSS . '</style></head><body><main>'
            . $body
            . '<details><summary>JSON</summary><pre>' . $this->escape($json) . '</pre></details>'
            . '</main></body></html>';
    }

    /**
     * @param array<int, array{string, string}> $problems checks with the overall status, as code and message
     */
    private function renderBanner(string $status, array $counts, int $total, array $problems): string
    {
        $heading = match ($status) {
            'fail' => sprintf('Fail — %d %s failing', $counts['fail'], $counts['fail'] === 1 ? 'check' : 'checks'),
            'warn' => sprintf('Warn — %d %s', $counts['warn'], $counts['warn'] === 1 ? 'warning' : 'warnings'),
            default => $total ? sprintf('Pass — all %d checks passing', $total) : 'Pass',
        };

        $lines = '';
        foreach (array_slice($problems, 0, 3) as [$code, $output]) {
            $lines .= '<p><span class="mono strong">' . $this->escape($code) . '</span>'
                . ($output !== '' ? ': ' . $this->escape($output) : '') . '</p>';
        }
        if (count($problems) > 3) {
            $lines .= '<p class="muted">and ' . (count($problems) - 3) . ' more</p>';
        }

        return '<section class="banner ' . $this->escape($status) . '">'
            . '<span class="banner-icon">' . $this->icon($status, 24) . '</span>'
            . '<div><h2>' . $this->escape($heading) . '</h2>' . $lines . '</div></section>';
    }

    private function renderStat(string $type, string $value, string $label): string
    {
        return '<div class="stat ' . $type . '"><span class="stat-value">' . $this->escape($value) . '</span>'
            . '<span class="stat-label">' . $this->escape($label) . '</span></div>';
    }

    /**
     * @param array<string, array<string, mixed>> $measurements the check's measurements, by name
     */
    private function renderRow(string $code, array $result, array $measurements = []): string
    {
        $status = (string) ($result['status'] ?? '');
        $output = (string) ($result['output'] ?? '');
        // The response time has a column of its own
        $responseTime = $measurements['responseTime']['observedValue'] ?? null;
        unset($measurements['responseTime']);
        $chips = $this->renderMeasurements($measurements) . $this->renderMeta((array) ($result['meta'] ?? []));

        return '<tr class="' . $this->escape($status) . '" data-check="' . $this->escape($code) . '">'
            . '<td class="mono strong">' . $this->escape($code) . '</td>'
            . '<td><span class="pill ' . $this->escape($status) . '">' . $this->icon($status, 14)
            . $this->escape($status) . '</span></td>'
            . '<td>' . ($output !== '' ? '<p>' . $this->escape($output) . '</p>' : '')
            . ($chips !== '' ? '<div class="chips">' . $chips . '</div>' : '') . '</td>'
            . '<td class="num mono">' . ($responseTime !== null
                ? $this->escape($this->formatDuration((float) $responseTime)) : '') . '</td>'
            . '</tr>';
    }

    private function formatDuration(float $ms): string
    {
        return match (true) {
            $ms < 0.1 => '<0.1 ms',
            $ms >= 1000 => round($ms / 1000, 2) . ' s',
            default => round($ms, 1) . ' ms',
        };
    }

    /**
     * Failures first, then warnings, then the rest, each in the order the checks ran.
     */
    private function sortProblemsFirst(array|\stdClass $checks): array
    {
        $rank = ['fail' => 0, 'warn' => 1];
        $checks = (array) $checks;
        $order = array_flip(array_keys($checks));
        uksort($checks, static function ($a, $b) use ($checks, $rank, $order) {
            $statusA = $rank[$checks[$a][0]['status'] ?? ''] ?? 2;
            $statusB = $rank[$checks[$b][0]['status'] ?? ''] ?? 2;

            return [$statusA, $order[$a]] <=> [$statusB, $order[$b]];
        });

        return $checks;
    }

    /**
     * One chip per measurement: its name and observedValue with observedUnit, e.g. "hitRate 99.5%".
     *
     * @param array<string, array<string, mixed>> $measurements
     */
    private function renderMeasurements(array $measurements): string
    {
        $chips = '';
        foreach ($measurements as $name => $measurement) {
            $chips .= '<span class="chip"><span class="chip-key">' . $this->escape((string) $name) . '</span>'
                . $this->escape($this->formatObserved($measurement['observedValue'] ?? '', $measurement['observedUnit'] ?? null))
                . '</span>';
        }

        return $chips;
    }

    private function formatObserved(mixed $value, ?string $unit): string
    {
        if (!is_int($value) && !is_float($value)) {
            return $this->formatValue($value);
        }

        return match ($unit) {
            null, '' => (string) $value,
            'percent' => $value . '%',
            'MiB' => $value >= 1024 ? round($value / 1024, 1) . ' GiB' : $value . ' MiB',
            default => $value . ' ' . $unit,
        };
    }

    /**
     * One chip per meta item; a nested object becomes a chip per key, a list is joined with commas.
     */
    private function renderMeta(array $meta): string
    {
        $chips = '';
        foreach ($meta as $key => $value) {
            if (is_array($value) && $value !== [] && !array_is_list($value)) {
                $chips .= $this->renderMeta($value);
                continue;
            }
            $chips .= '<span class="chip"><span class="chip-key">' . $this->escape((string) $key) . '</span>'
                . $this->escape($this->formatValue($value)) . '</span>';
        }

        return $chips;
    }

    private function formatValue(mixed $value): string
    {
        if (is_array($value)) {
            return implode(', ', array_map(fn ($item) => $this->formatValue($item), $value));
        }
        if (is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}/', $value)) {
            $time = strtotime($value);
            if ($time !== false) {
                return gmdate('Y-m-d H:i:s', $time) . ' UTC';
            }
        }

        return match (true) {
            is_bool($value) => $value ? 'true' : 'false',
            $value === null => 'null',
            default => (string) $value,
        };
    }

    /**
     * Inline SVG: a cross for fail, an exclamation mark for warn, a check mark for pass.
     */
    private function icon(string $status, int $size): string
    {
        $path = match ($status) {
            'fail' => 'M18 6 6 18M6 6l12 12',
            'warn' => 'M12 7v6M12 17h.01',
            default => 'M20 6 9 17l-5-5',
        };

        return sprintf(
            '<svg width="%1$d" height="%1$d" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" '
            . 'stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="%2$s"/></svg>',
            $size,
            $path
        );
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private const CSS = ':root{color-scheme:light dark;'
        . '--bg:#f0f0f0;--card:#fff;--fg:#1e1e1e;--title:#202945;--muted:#5c5f66;--line:#d9d9d9;--row:#f0f0f0;--chip:#f0f0f0;'
        . '--fail:#a13b3b;--fail-text:#a13b3b;--fail-bg:#f6e7e7;--fail-row:#fcf6f6;'
        . '--warn:#c98a1b;--warn-text:#8a5300;--warn-bg:#fbf0d9;'
        . '--pass:#2e7d4f;--pass-text:#256b42;--pass-bg:#e6f2ea;--time:#d9d9d9}'
        . '@media (prefers-color-scheme:dark){:root{'
        . '--bg:#14161b;--card:#1d2027;--fg:#e4e5e8;--title:#eef0f6;--muted:#9a9ea8;--line:#343842;--row:#2a2e37;--chip:#2a2e37;'
        . '--fail:#d46a6a;--fail-text:#ec8f8f;--fail-bg:#3a2224;--fail-row:#2a1f22;'
        . '--warn:#d9a441;--warn-text:#e8bf6d;--warn-bg:#3a3020;'
        . '--pass:#4fae74;--pass-text:#7cc99a;--pass-bg:#1f3428;--time:#4a4f5a}}'
        . '*{box-sizing:border-box}'
        . 'body{margin:0;background:var(--bg);color:var(--fg);font:16px/1.45 system-ui,-apple-system,"Segoe UI",sans-serif}'
        . 'main{max-width:1120px;margin:0 auto;padding:48px 24px 64px;display:flex;flex-direction:column;gap:24px}'
        . '.mono{font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace}.strong{font-weight:600}'
        . '.muted{color:var(--muted)}'
        . '.eyebrow{margin:0 0 12px;color:var(--muted);font-size:14px;font-weight:600}'
        . 'h1{margin:0 0 12px;color:var(--title);font:700 40px/1.1 system-ui,-apple-system,"Segoe UI",sans-serif;letter-spacing:-.01em;word-break:break-word}'
        . '.facts{display:flex;flex-wrap:wrap;gap:8px 24px;margin:0;font-size:14px}.facts div{display:flex;gap:6px}'
        . '.facts dt{color:var(--muted)}.facts dd{margin:0;font-weight:600}'
        . '.banner{display:flex;gap:16px;align-items:flex-start;padding:20px 24px;border-radius:5px}'
        . '.banner h2{margin:0 0 4px;font:700 28px/1.15 system-ui,-apple-system,"Segoe UI",sans-serif}.banner p{margin:2px 0 0;font-size:15px}'
        . '.banner-icon{flex-shrink:0;width:44px;height:44px;border-radius:50%;display:flex;align-items:center;justify-content:center;color:#fff}'
        . '.banner.fail{background:var(--fail-bg);box-shadow:inset 6px 0 var(--fail)}.banner.fail h2{color:var(--fail-text)}.banner.fail .banner-icon{background:var(--fail)}'
        . '.banner.warn{background:var(--warn-bg);box-shadow:inset 6px 0 var(--warn)}.banner.warn h2{color:var(--warn-text)}.banner.warn .banner-icon{background:var(--warn)}'
        . '.banner.pass{background:var(--pass-bg);box-shadow:inset 6px 0 var(--pass)}.banner.pass h2{color:var(--pass-text)}.banner.pass .banner-icon{background:var(--pass)}'
        . '.stats{display:grid;grid-template-columns:repeat(4,1fr);gap:16px}'
        . '@media (max-width:720px){.stats{grid-template-columns:repeat(2,1fr)}h1{font-size:30px}.banner h2{font-size:22px}}'
        . '.stat{background:var(--card);border-radius:5px;border-top:4px solid var(--time);padding:20px 24px;display:flex;flex-direction:column;gap:4px}'
        . '.stat-value{font:700 36px/1.1 system-ui,-apple-system,"Segoe UI",sans-serif;color:var(--title)}.stat-label{color:var(--muted);font-size:14px}'
        . '.stat.fail{border-top-color:var(--fail)}.stat.fail .stat-value{color:var(--fail-text)}'
        . '.stat.warn{border-top-color:var(--warn)}.stat.warn .stat-value{color:var(--warn-text)}'
        . '.stat.pass{border-top-color:var(--pass)}.stat.pass .stat-value{color:var(--pass-text)}'
        . '.card{background:var(--card);border-radius:5px}.scroll{overflow-x:auto}'
        . 'table{width:100%;min-width:680px;border-collapse:collapse;table-layout:fixed;font-size:14px}'
        . '.c-check{width:190px}.c-status{width:110px}.c-time{width:120px}'
        . 'th{text-align:left;font-size:13px;font-weight:600;color:var(--muted);padding:12px 16px;border-bottom:1px solid var(--line);white-space:nowrap}'
        . 'td{padding:14px 16px;border-bottom:1px solid var(--row);vertical-align:top}tbody tr:last-child td{border-bottom:0}'
        . 'td.mono.strong{white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.num{text-align:right}'
        . 'td p{margin:0}tr.fail{background:var(--fail-row)}'
        . 'tr.fail td:first-child{box-shadow:inset 4px 0 var(--fail)}tr.warn td:first-child{box-shadow:inset 4px 0 var(--warn)}'
        . '.pill{display:inline-flex;align-items:center;gap:6px;padding:3px 10px 3px 7px;border-radius:100px;font-size:12px;font-weight:700}'
        . '.pill.fail{background:var(--fail-bg);color:var(--fail-text)}.pill.warn{background:var(--warn-bg);color:var(--warn-text)}'
        . '.pill.pass{background:var(--pass-bg);color:var(--pass-text)}'
        . '.chips{display:flex;flex-wrap:wrap;gap:6px}td p+.chips{margin-top:8px}'
        . '.chip{display:inline-flex;gap:6px;background:var(--chip);border-radius:4px;padding:2px 8px;font:12px/1.6 ui-monospace,SFMono-Regular,Menlo,monospace;word-break:break-all}'
        . '.chip-key{color:var(--muted)}'
        . 'details{font-size:14px}summary{cursor:pointer;color:var(--muted)}'
        . 'pre{background:var(--card);border-radius:5px;padding:16px;font:13px/1.45 ui-monospace,SFMono-Regular,Menlo,monospace;white-space:pre-wrap;word-break:break-word}';
}
