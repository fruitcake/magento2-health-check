<?php

declare(strict_types=1);

namespace Fruitcake\HealthCheck\Block\Adminhtml\System\Config;

use Fruitcake\HealthCheck\Model\Format\FormatPool;
use Magento\Backend\Block\Template\Context;
use Magento\Config\Block\System\Config\Form\Field;
use Magento\Framework\Data\Form\Element\AbstractElement;
use Magento\Framework\UrlInterface;
use Magento\Framework\View\Helper\SecureHtmlRenderer;

/**
 * Read-only URL of the endpoint with ?key=, built from the endpoint and key fields as they are typed,
 * with a button that copies it. The input has no name, so nothing is saved.
 */
class MonitorUrlField extends Field
{
    public function __construct(
        Context $context,
        private readonly SecureHtmlRenderer $secureHtmlRenderer,
        private readonly FormatPool $formatPool,
        array $data = []
    ) {
        parent::__construct($context, $data, $secureHtmlRenderer);
    }

    public function render(AbstractElement $element): string
    {
        // Not a setting: no scope label and no "Use system value" checkbox
        $element->unsScope()->unsCanUseWebsiteValue()->unsCanUseDefaultValue();

        return parent::render($element);
    }

    protected function _getElementHtml(AbstractElement $element): string
    {
        $inputId = $element->getHtmlId();
        $buttonId = $inputId . '_copy';
        $formatId = $inputId . '_format';
        $prefix = substr($inputId, 0, -strlen('monitor_url'));

        $script = sprintf(
            "var output = document.getElementById(%s);"
            . "var endpoint = document.getElementById(%s);"
            . "var key = document.getElementById(%s);"
            . "var format = document.getElementById(%s);"
            . "var update = function () {"
            . "  var path = (endpoint && endpoint.value ? endpoint.value : 'health').replace(/^\\/+|\\/+$/g, '');"
            . "  var value = key ? key.value.trim() : '';"
            . "  var query = [];"
            . "  if (value) { query.push('key=' + encodeURIComponent(value)); }"
            . "  if (format.value) { query.push('format=' + encodeURIComponent(format.value)); }"
            . "  output.value = %s + path + (query.length ? '?' + query.join('&') : '');"
            . "};"
            . "[endpoint, key, format].forEach(function (field) {"
            . "  if (field) { field.addEventListener('input', update); field.addEventListener('change', update); }"
            . "});"
            . "update();",
            json_encode($inputId),
            json_encode($prefix . 'endpoint'),
            json_encode($prefix . 'key'),
            json_encode($formatId),
            json_encode($this->getStorefrontUrl())
        );

        $copy = sprintf(
            "var output = document.getElementById(%s);"
            . "output.select();"
            . "if (navigator.clipboard) { navigator.clipboard.writeText(output.value); } else { document.execCommand('copy'); }"
            . "this.textContent = %s;",
            json_encode($inputId),
            json_encode((string) __('Copied'))
        );

        return sprintf(
            '<input type="text" id="%s" class="input-text admin__control-text" readonly="readonly" value="">',
            $this->escapeHtmlAttr($inputId)
        )
            // Only changes the URL to copy; without a name, nothing is saved
            . sprintf(
                '<select id="%s" class="admin__control-select" style="margin: 8px 8px 0 0" title="%s">%s</select>',
                $this->escapeHtmlAttr($formatId),
                $this->escapeHtmlAttr(__('Format of the copied URL')),
                $this->getFormatOptions()
            )
            . sprintf(
                '<button type="button" id="%s" class="action-default" style="margin-top: 8px">%s</button>',
                $this->escapeHtmlAttr($buttonId),
                $this->escapeHtml(__('Copy URL'))
            )
            . $this->secureHtmlRenderer->renderTag('script', [], $script, false)
            . $this->secureHtmlRenderer->renderEventListenerAsTag('onclick', $copy, '#' . $buttonId);
    }

    /**
     * "Auto" plus every format in the pool, so a format another module adds shows up too.
     */
    private function getFormatOptions(): string
    {
        $options = sprintf('<option value="">%s</option>', $this->escapeHtml(__('Auto: by the client')));
        foreach ($this->formatPool->getFormats() as $name => $format) {
            $options .= sprintf(
                '<option value="%s">%s</option>',
                $this->escapeHtmlAttr((string) $name),
                $this->escapeHtml(__('Always %1', $format->getLabel()))
            );
        }

        return $options;
    }

    /**
     * Frontend base URL of the default store view, with the store code when URLs contain it.
     */
    private function getStorefrontUrl(): string
    {
        $store = $this->_storeManager->getDefaultStoreView();
        $url = $store
            ? $store->getBaseUrl(UrlInterface::URL_TYPE_LINK, true)
            : $this->_storeManager->getStore()->getBaseUrl(UrlInterface::URL_TYPE_LINK, true);

        return rtrim((string) $url, '/') . '/';
    }
}
