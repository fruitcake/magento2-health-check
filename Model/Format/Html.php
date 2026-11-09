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
        $checks = $this->sortProblemsFirst($data['checks'] ?? []);

        $counts = ['fail' => 0, 'warn' => 0, 'pass' => 0];
        $totalMs = 0.0;
        $problems = '';
        $passing = '';
        foreach ($checks as $code => $results) {
            foreach ((array) $results as $result) {
                $checkStatus = (string) ($result['status'] ?? '');
                $counts[$checkStatus] = ($counts[$checkStatus] ?? 0) + 1;
                $totalMs += (float) ($result['durationMs'] ?? 0);
                $row = $this->renderRow((string) $code, $result);
                if ($checkStatus === 'pass') {
                    $passing .= $row;
                } else {
                    $problems .= $row;
                }
            }
        }

        if ($checks) {
            $body = ($problems !== '' ? $this->renderTable('Needs attention', $problems) : '')
                . ($passing !== '' ? $this->renderTable('Passing', $passing) : '');
        } else {
            $body = '<p class="muted">Checks are only listed with the key, when Show Check Details is on.</p>';
        }

        $summary = $checks
            ? implode(' · ', array_filter([
                $counts['fail'] ? $counts['fail'] . ' failing' : '',
                $counts['warn'] ? $counts['warn'] . ($counts['warn'] === 1 ? ' warning' : ' warnings') : '',
                $counts['pass'] ? $counts['pass'] . ' passing' : '',
            ]))
            : '';
        $context = array_filter([
            isset($data['serviceId']) ? 'Node ' . $data['serviceId'] : '',
            isset($data['releaseId']) ? 'Release ' . $data['releaseId'] : '',
            'Checked ' . gmdate('Y-m-d H:i:s') . ' UTC',
            $checks ? 'in ' . $this->formatDuration($totalMs) : '',
        ]);

        return '<!doctype html><html lang="en"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<meta name="robots" content="noindex, nofollow">'
            . '<title>' . $this->escape(self::HEADINGS[$status] ?? 'Health') . '</title>'
            . '<style>' . self::CSS . '</style></head><body><main>'
            . '<header class="banner ' . $this->escape($status) . '">'
            . '<h1>' . $this->escape(self::HEADINGS[$status] ?? 'Health') . '</h1>'
            . ($summary !== '' ? '<p class="summary">' . $this->escape($summary) . '</p>' : '')
            . '<p class="context">' . $this->escape(implode(' · ', $context)) . '</p>'
            . '</header>'
            . $body
            . '<details><summary>JSON</summary><pre>' . $this->escape($json) . '</pre></details>'
            . '</main></body></html>';
    }

    private const HEADINGS = ['pass' => 'Healthy', 'warn' => 'Healthy, with warnings', 'fail' => 'Unhealthy'];

    private function renderTable(string $title, string $rows): string
    {
        // Fixed columns, so the tables line up
        return '<h2>' . $this->escape($title) . '</h2><div class="scroll"><table>'
            . '<colgroup><col class="c-check"><col class="c-status"><col><col class="c-meta"><col class="c-time"></colgroup>'
            . '<thead><tr><th>Check</th><th>Status</th><th>Output</th><th>Meta</th><th class="num">Duration</th></tr></thead>'
            . '<tbody>' . $rows . '</tbody></table></div>';
    }

    private function renderRow(string $code, array $result): string
    {
        $status = (string) ($result['status'] ?? '');

        return sprintf(
            '<tr class="%s" data-check="%s"><td><code>%s</code></td><td><span class="badge %s">%s</span></td>'
            . '<td>%s</td><td class="meta">%s</td><td class="num">%s</td></tr>',
            $this->escape($status),
            $this->escape($code),
            $this->escape($code),
            $this->escape($status),
            $this->escape($status),
            $this->escape((string) ($result['output'] ?? '')),
            $this->renderMeta((array) ($result['meta'] ?? [])),
            isset($result['durationMs']) ? $this->escape($this->formatDuration((float) $result['durationMs'])) : ''
        );
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
     * One "key: value" line per meta item. A list is joined with commas, a nested object becomes "key: value" pairs.
     */
    private function renderMeta(array $meta): string
    {
        $lines = [];
        foreach ($meta as $key => $value) {
            $lines[] = '<div><span class="muted">' . $this->escape((string) $key) . ':</span> '
                . $this->escape($this->formatValue($value)) . '</div>';
        }

        return implode('', $lines);
    }

    private function formatValue(mixed $value): string
    {
        if (is_array($value)) {
            if (array_is_list($value)) {
                return implode(', ', array_map(fn ($item) => $this->formatValue($item), $value));
            }
            $pairs = [];
            foreach ($value as $key => $item) {
                $pairs[] = $key . ': ' . $this->formatValue($item);
            }

            return implode(', ', $pairs);
        }

        return match (true) {
            is_bool($value) => $value ? 'true' : 'false',
            $value === null => 'null',
            default => (string) $value,
        };
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private const CSS = ':root{color-scheme:light dark;--bg:#fff;--fg:#1f2328;--muted:#656d76;--line:#d0d7de;--card:#f6f8fa;'
        . '--pass:#1a7f37;--warn:#9a6700;--fail:#cf222e;--pass-bg:#dafbe1;--warn-bg:#fff8c5;--fail-bg:#ffebe9}'
        . '@media (prefers-color-scheme:dark){:root{--bg:#0d1117;--fg:#e6edf3;--muted:#8d96a0;--line:#30363d;--card:#161b22;'
        . '--pass:#3fb950;--warn:#d29922;--fail:#f85149;--pass-bg:#12261e;--warn-bg:#272115;--fail-bg:#2d1517}}'
        . 'body{margin:0;background:var(--bg);color:var(--fg);font:15px/1.5 system-ui,sans-serif}'
        . 'main{max-width:1100px;margin:0 auto;padding:24px 16px}'
        . '.banner{border:1px solid var(--line);border-left:6px solid var(--muted);border-radius:8px;padding:16px 20px;background:var(--card)}'
        . '.banner.pass{border-left-color:var(--pass);background:var(--pass-bg)}'
        . '.banner.warn{border-left-color:var(--warn);background:var(--warn-bg)}'
        . '.banner.fail{border-left-color:var(--fail);background:var(--fail-bg)}'
        . 'h1{font-size:22px;margin:0}.summary{margin:4px 0 0;font-weight:600}.context{margin:4px 0 0;color:var(--muted);font-size:13px}'
        . 'h2{font-size:15px;margin:28px 0 8px;color:var(--muted);font-weight:600}'
        . '.muted{color:var(--muted)}.scroll{overflow-x:auto}table{width:100%;min-width:760px;border-collapse:collapse;table-layout:fixed}'
        . '.c-check{width:180px}.c-status{width:72px}.c-meta{width:34%}.c-time{width:84px}td code{white-space:nowrap}'
        . 'th,td{text-align:left;padding:8px 10px;border-bottom:1px solid var(--line);vertical-align:top}'
        . 'th{font-weight:600;color:var(--muted);font-size:13px}.num{text-align:right;white-space:nowrap;color:var(--muted)}'
        . 'tr.fail td:first-child{box-shadow:inset 3px 0 var(--fail)}tr.warn td:first-child{box-shadow:inset 3px 0 var(--warn)}'
        . 'code,pre{font:13px/1.4 ui-monospace,monospace;word-break:break-word}pre{white-space:pre-wrap}'
        . '.meta{font-size:13px;word-break:break-word}'
        . '.badge{display:inline-block;padding:1px 8px;border-radius:10px;font-size:12px;font-weight:600;color:#fff;background:var(--muted)}'
        . '.badge.pass{background:var(--pass)}.badge.warn{background:var(--warn)}.badge.fail{background:var(--fail)}'
        . 'details{margin-top:28px}summary{cursor:pointer;color:var(--muted)}';
}
