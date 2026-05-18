<?php

declare(strict_types=1);

namespace Retnly\MagentoBridge\Block\Adminhtml\System\Config;

use Magento\Config\Block\System\Config\Form\Field;
use Magento\Framework\Data\Form\Element\AbstractElement;

/**
 * Renders the API Key field as a masked password input without Magento's
 * hardcoded maxlength="5" that the built-in Obscure element type imposes.
 * Encryption-at-rest is still handled by the Encrypted backend model.
 */
class ApiKey extends Field
{
    protected function _getElementHtml(AbstractElement $element): string
    {
        $element->setType('password');
        $element->setClass('input-text');
        $element->setMaxlength('');
        return $element->getElementHtml();
    }
}
