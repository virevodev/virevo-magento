<?php
/**
 * @license GPLv2 or later
 */

namespace Virevo\Payment\Model\Config\Source;

class Mode implements \Magento\Framework\Option\ArrayInterface
{
    public function toOptionArray()
    {
        return [
            ['value' => 'test', 'label' => __('Test (bac à sable)')],
            ['value' => 'live', 'label' => __('Live (réel)')],
        ];
    }
}
