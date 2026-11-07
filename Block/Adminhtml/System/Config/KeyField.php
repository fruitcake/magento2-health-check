<?php

declare(strict_types=1);

namespace Fruitcake\HealthCheck\Block\Adminhtml\System\Config;

use Magento\Backend\Block\Template\Context;
use Magento\Config\Block\System\Config\Form\Field;
use Magento\Framework\Data\Form\Element\AbstractElement;
use Magento\Framework\View\Helper\SecureHtmlRenderer;

/**
 * Key field with a button that fills in a random key in the browser and shows it, so it can be copied before saving.
 */
class KeyField extends Field
{
    public function __construct(
        Context $context,
        private readonly SecureHtmlRenderer $secureHtmlRenderer,
        array $data = []
    ) {
        parent::__construct($context, $data, $secureHtmlRenderer);
    }

    protected function _getElementHtml(AbstractElement $element): string
    {
        $inputId = $element->getHtmlId();
        $buttonId = $inputId . '_generate';

        $script = sprintf(
            "var input = document.getElementById(%s);"
            . "var bytes = new Uint8Array(24);"
            . "window.crypto.getRandomValues(bytes);"
            . "input.value = Array.from(bytes, function (b) { return b.toString(16).padStart(2, '0'); }).join('');"
            . "input.type = 'text';"
            . "input.dispatchEvent(new Event('change', {bubbles: true}));"
            . "input.select();",
            json_encode($inputId)
        );

        return parent::_getElementHtml($element)
            . sprintf(
                '<button type="button" id="%s" class="action-default" style="margin-top: 8px">%s</button>',
                $this->escapeHtmlAttr($buttonId),
                $this->escapeHtml(__('Generate Key'))
            )
            . $this->secureHtmlRenderer->renderEventListenerAsTag('onclick', $script, '#' . $buttonId);
    }
}
