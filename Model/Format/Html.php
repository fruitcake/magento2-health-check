<?php

declare(strict_types=1);

namespace Fruitcake\HealthCheck\Model\Format;

/**
 * A page for people who open the endpoint in a browser: the status, a table of the checks, and the
 * JSON itself, so a keyword check on e.g. "pass" still matches if a monitor ever gets this page.
 * Every value is escaped: messages can hold exception texts.
 */
class Html
{
    public function render(array $data, string $json): string
    {
        $status = (string) ($data['status'] ?? '');
        $rows = '';
        foreach ($data['checks'] ?? [] as $code => $results) {
            foreach ((array) $results as $result) {
                $rows .= sprintf(
                    '<tr><td>%s</td><td><span class="badge %s">%s</span></td><td>%s</td><td class="num">%s</td><td><code>%s</code></td></tr>',
                    $this->escape((string) $code),
                    $this->escape((string) ($result['status'] ?? '')),
                    $this->escape((string) ($result['status'] ?? '')),
                    $this->escape((string) ($result['output'] ?? '')),
                    isset($result['durationMs']) ? $this->escape($result['durationMs'] . ' ms') : '',
                    isset($result['meta'])
                        ? $this->escape((string) json_encode($result['meta'], JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE))
                        : ''
                );
            }
        }

        $node = array_filter([
            isset($data['serviceId']) ? 'Node ' . $data['serviceId'] : '',
            isset($data['releaseId']) ? 'release ' . $data['releaseId'] : '',
        ]);

        $table = $rows !== ''
            ? '<table><thead><tr><th>Check</th><th>Status</th><th>Output</th><th class="num">Duration</th><th>Meta</th></tr></thead>'
                . '<tbody>' . $rows . '</tbody></table>'
            : '<p class="muted">Checks are only listed with the key, when Show Check Details is on.</p>';

        return '<!doctype html><html lang="en"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<meta name="robots" content="noindex, nofollow">'
            . '<title>Health: ' . $this->escape($status) . '</title>'
            . '<style>' . self::CSS . '</style></head><body><main>'
            . '<h1><span class="badge ' . $this->escape($status) . '">' . $this->escape($status) . '</span> Health</h1>'
            . ($node ? '<p class="muted">' . $this->escape(implode(', ', $node)) . '</p>' : '')
            . (isset($data['output']) ? '<p>' . $this->escape((string) $data['output']) . '</p>' : '')
            . $table
            . '<details><summary>JSON</summary><pre>' . $this->escape($json) . '</pre></details>'
            . '</main></body></html>';
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private const CSS = ':root{color-scheme:light dark;--bg:#fff;--fg:#1f2328;--muted:#656d76;--line:#d0d7de;'
        . '--pass:#1a7f37;--warn:#9a6700;--fail:#cf222e}'
        . '@media (prefers-color-scheme:dark){:root{--bg:#0d1117;--fg:#e6edf3;--muted:#8d96a0;--line:#30363d;'
        . '--pass:#3fb950;--warn:#d29922;--fail:#f85149}}'
        . 'body{margin:0;background:var(--bg);color:var(--fg);font:15px/1.5 system-ui,sans-serif}'
        . 'main{max-width:1100px;margin:0 auto;padding:24px 16px}h1{font-size:22px;margin:0 0 8px}'
        . '.muted{color:var(--muted)}table{width:100%;border-collapse:collapse;margin:16px 0;display:block;overflow-x:auto}'
        . 'th,td{text-align:left;padding:6px 10px;border-bottom:1px solid var(--line);vertical-align:top}'
        . 'th{font-weight:600;color:var(--muted)}.num{text-align:right;white-space:nowrap}'
        . 'code,pre{font:13px/1.4 ui-monospace,monospace;word-break:break-word}pre{white-space:pre-wrap}'
        . '.badge{display:inline-block;padding:1px 8px;border-radius:10px;font-size:13px;font-weight:600;color:#fff;background:var(--muted)}'
        . '.pass{background:var(--pass)}.warn{background:var(--warn)}.fail{background:var(--fail)}'
        . 'summary{cursor:pointer;color:var(--muted)}';
}
