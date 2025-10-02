<?php
/**
 * Copyright © Reach Digital (https://www.reachdigital.io/)
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace ReachDigital\Vendit\Model\Config\Backend;

use Magento\Framework\App\Config\Value;
use Magento\Framework\Serialize\SerializerInterface;

class AttributeMapping extends Value
{
    public function __construct(
        \Magento\Framework\Model\Context $context,
        \Magento\Framework\Registry $registry,
        \Magento\Framework\App\Config\ScopeConfigInterface $config,
        \Magento\Framework\App\Cache\TypeListInterface $cacheTypeList,
        private readonly SerializerInterface $serializer,
        \Magento\Framework\Model\ResourceModel\AbstractResource $resource = null,
        \Magento\Framework\Data\Collection\AbstractDb $resourceCollection = null,
        array $data = []
    ) {
        parent::__construct($context, $registry, $config, $cacheTypeList, $resource, $resourceCollection, $data);
    }

    public function beforeSave()
    {
        $value = $this->getValue();

        if (is_array($value)) {
            // Remove empty rows
            $value = array_filter($value, function ($row) {
                return !empty($row['attribute_code']) && !empty($row['spec_name']);
            });

            $this->setValue($this->serializer->serialize($value));
        }

        return parent::beforeSave();
    }

    public function afterLoad()
    {
        $value = $this->getValue();

        if ($value && !is_array($value)) {
            try {
                $this->setValue($this->serializer->unserialize($value));
            } catch (\Exception $e) {
                $this->setValue([]);
            }
        }

        return parent::afterLoad();
    }
}
