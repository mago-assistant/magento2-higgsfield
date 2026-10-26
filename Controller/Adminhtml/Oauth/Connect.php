<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Higgsfield\Controller\Adminhtml\Oauth;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\ResultInterface;
use MagoAssistant\Higgsfield\Service\Client\OAuthService;

/**
 * Starts the OAuth authorization with Higgsfield and sends the admin to its login page.
 */
class Connect extends Action implements HttpGetActionInterface
{
    public const ADMIN_RESOURCE = 'MagoAssistant_Higgsfield::config';
    public const SESSION_KEY = 'mago_higgsfield_oauth';
    public const CONFIG_SECTION = 'mago_higgsfield';

    public function __construct(
        Context $context,
        private readonly OAuthService $oauth
    ) {
        parent::__construct($context);
    }

    public function execute(): ResultInterface
    {
        $redirectUri = $this->getUrl('mago_higgsfield/oauth/callback', ['_nosecret' => true]);
        $start = $this->oauth->startAuthorization($redirectUri);
        if (isset($start['error'])) {
            $this->messageManager->addErrorMessage(__('Connecting to Higgsfield failed: %1', $start['error']));
            return $this->resultRedirectFactory->create()
                ->setPath('adminhtml/system_config/edit', ['section' => self::CONFIG_SECTION]);
        }

        $this->_session->setData(self::SESSION_KEY, [
            'state' => $start['state'],
            'verifier' => $start['verifier'],
            'redirect_uri' => $redirectUri,
        ]);

        return $this->resultRedirectFactory->create()->setUrl($start['url']);
    }
}
