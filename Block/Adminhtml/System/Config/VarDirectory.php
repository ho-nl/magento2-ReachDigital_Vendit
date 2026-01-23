<?php
/**
 * Copyright © Reach Digital (https://www.reachdigital.io/)
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace ReachDigital\Vendit\Block\Adminhtml\System\Config;

use Magento\Backend\Block\Template\Context;
use Magento\Config\Block\System\Config\Form\Field;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Data\Form\Element\AbstractElement;

class VarDirectory extends Field
{
    public function __construct(Context $context, private readonly DirectoryList $directoryList, array $data = [])
    {
        parent::__construct($context, $data);
    }

    protected function _getElementHtml(AbstractElement $element): string
    {
        $varPath = $this->directoryList->getPath(DirectoryList::VAR_DIR);

        return sprintf(
            '<code>%s/vendit/</code><br/><small>All directory paths below are relative to this location.</small>',
            $this->escapeHtml($varPath),
        );
    }
}
