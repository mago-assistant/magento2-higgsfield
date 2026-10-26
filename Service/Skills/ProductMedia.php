<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Higgsfield\Service\Skills;

use MagoAssistant\Mago\Api\Tool\PresentableToolInterface;
use MagoAssistant\Mago\Service\Skills\AbstractSkill;

class ProductMedia extends AbstractSkill implements PresentableToolInterface
{
    private const STATUS_MESSAGES = [
        'generate_image' => 'Starting image generation...',
        'generate_video' => 'Starting video generation...',
        'check_status' => 'Checking generation status...',
        'attach_image' => 'Adding image to product...',
    ];

    public function getName(): string
    {
        return 'product_media';
    }

    public function getDisplayName(): string
    {
        return 'Product media (Higgsfield)';
    }

    /**
     * Status line the chat shows while the action runs.
     *
     * @param string $action
     * @param array<string,mixed> $input
     * @return string|null
     */
    public function getStatusMessage(string $action, array $input): ?string
    {
        return self::STATUS_MESSAGES[$action] ?? null;
    }

    protected function getBaseDescription(): string
    {
        return 'Generate new images (Nano Banana 2) or short videos (Seedance 2.0 or Kling 3.0) of an existing '
            . 'product from its main image with Higgsfield (needs a connected Higgsfield account in the '
            . 'configuration). Generation runs in the background: start it, '
            . 'then check the request until it is completed.';
    }

    /**
     * ACL resource an admin needs to use this tool.
     *
     * @param array<string,mixed> $input
     * @return string
     */
    public function getMagentoAcl(array $input = []): string
    {
        return 'Magento_Catalog::products';
    }

    protected function getBaseInstructions(): string
    {
        return <<<'TEXT'
Write the prompt in English, even when the admin writes in another language. Describe what should change
(scene, background, lighting, motion, camera) and keep the product itself as in its main image; do not ask
Higgsfield to alter the product's shape, colour, text or logo unless the admin asks for it.
Every generation costs credits, so start one generation per request of the admin and never retry a failed
one on your own.
TEXT;
    }
}
