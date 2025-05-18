<?php

namespace Gab\Dropshipping\Model\Config\Source;

use Magento\Framework\Option\ArrayInterface;

class Cron implements ArrayInterface
{
    /**
     * Return cron frequency options
     *
     * @return array
     */
    public function toOptionArray()
    {
        return [
            ['value' => 'hourly', 'label' => __('Hourly')],
            ['value' => 'daily', 'label' => __('Daily')],
            ['value' => 'weekly', 'label' => __('Weekly')],
        ];
    }
}
