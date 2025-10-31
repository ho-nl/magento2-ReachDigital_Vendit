<?php
/**
 * Copyright © Reach Digital (https://www.reachdigital.io/)
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace ReachDigital\Vendit\Block\Adminhtml\System\Config;

use Magento\Backend\Block\Template\Context;
use Magento\Config\Block\System\Config\Form\Field;
use Magento\Framework\Component\ComponentRegistrar;
use Magento\Framework\Data\Form\Element\AbstractElement;

class Version extends Field
{
    public function __construct(
        Context $context,
        private readonly ComponentRegistrar $componentRegistrar,
        array $data = [],
    ) {
        parent::__construct($context, $data);
    }

    protected function _getElementHtml(AbstractElement $element): string
    {
        $version = $this->getModuleVersion();

        if ($version) {
            return sprintf('<strong style="font-size: 14px;">v%s</strong>', $this->escapeHtml($version));
        }

        return '<em>Version not found</em>';
    }

    private function getModuleVersion(): ?string
    {
        try {
            $modulePath = $this->componentRegistrar->getPath(ComponentRegistrar::MODULE, 'ReachDigital_Vendit');

            if (!$modulePath) {
                return null;
            }

            $composerJsonPath = $modulePath . '/composer.json';

            if (!file_exists($composerJsonPath)) {
                return null;
            }

            $composerJson = file_get_contents($composerJsonPath);
            $composerData = json_decode($composerJson, true);

            return $composerData['version'] ?? null;
        } catch (\Exception $e) {
            return null;
        }
    }
}
