<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Higgsfield\Block\Adminhtml\System\Config;

use Magento\Backend\Block\Template\Context;
use Magento\Config\Block\System\Config\Form\Field;
use Magento\Framework\Data\Form\Element\AbstractElement;
use Magento\Framework\View\Helper\SecureHtmlRenderer;
use MagoAssistant\Higgsfield\Service\Client\OAuthService;

/**
 * Shows the Higgsfield connection state with a connect or disconnect button.
 */
class Connection extends Field
{
    private const DISCONNECT_BUTTON_ID = 'mago-higgsfield-disconnect';

    /**
     * @param Context $context
     * @param OAuthService $oauth
     * @param SecureHtmlRenderer $htmlRenderer
     * @param array<string,mixed> $data
     */
    public function __construct(
        Context $context,
        private readonly OAuthService $oauth,
        private readonly SecureHtmlRenderer $htmlRenderer,
        array $data = []
    ) {
        parent::__construct($context, $data, $htmlRenderer);
    }

    /**
     * The connection is no setting, so there is no scope label or "use default" checkbox to show.
     *
     * @param AbstractElement $element
     * @return string
     */
    public function render(AbstractElement $element): string
    {
        $element->unsScope()->unsCanUseWebsiteValue()->unsCanUseDefaultValue();

        return parent::render($element);
    }

    protected function _getElementHtml(AbstractElement $element): string
    {
        $status = $this->oauth->status();
        $connectUrl = $this->escapeUrl($this->getUrl('mago_higgsfield/oauth/connect'));

        if (!$status['connected']) {
            return '<p>' . $this->escapeHtml(__('Not connected.')) . '</p>'
                . '<a class="action-default scalable" href="' . $connectUrl . '"><span>'
                . $this->escapeHtml(__('Connect with Higgsfield')) . '</span></a>';
        }

        $account = $status['email'] !== '' ? $status['email'] : (string)__('your Higgsfield account');
        $html = '<p>' . $this->escapeHtml(__('Connected as %1.', $account)) . '</p>';
        if (!$status['can_refresh'] && $status['expires_at'] > 0) {
            $html .= '<p class="note"><span>' . $this->escapeHtml(__(
                'Higgsfield gave no refresh token; reconnect after %1.',
                $this->formatDate(
                    new \DateTimeImmutable('@' . $status['expires_at']),
                    \IntlDateFormatter::MEDIUM,
                    true
                )
            )) . '</span></p>';
        }

        // The configuration page is one form already, so the POST is built on click rather than nested.
        $disconnectUrl = $this->escapeJs($this->getUrl('mago_higgsfield/oauth/disconnect'));
        $formKey = $this->escapeJs($this->getFormKey());
        $onClick = 'var form = document.createElement("form");'
            . 'form.method = "post";'
            . 'form.action = "' . $disconnectUrl . '";'
            . 'var key = document.createElement("input");'
            . 'key.type = "hidden";'
            . 'key.name = "form_key";'
            . 'key.value = "' . $formKey . '";'
            . 'form.appendChild(key);'
            . 'document.body.appendChild(form);'
            . 'form.submit();';

        return $html
            . '<a class="action-default scalable" href="' . $connectUrl . '"><span>'
            . $this->escapeHtml(__('Reconnect')) . '</span></a> '
            . '<button type="button" class="action-default scalable" id="' . self::DISCONNECT_BUTTON_ID . '"><span>'
            . $this->escapeHtml(__('Disconnect')) . '</span></button>'
            . $this->htmlRenderer->renderEventListenerAsTag('onclick', $onClick, '#' . self::DISCONNECT_BUTTON_ID);
    }
}
