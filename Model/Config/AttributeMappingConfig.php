<?php
/**
 * Copyright © Reach Digital (https://www.reachdigital.io/)
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace ReachDigital\Vendit\Model\Config;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Serialize\SerializerInterface;

class AttributeMappingConfig
{
    public const XML_PATH_ATTRIBUTE_MAPPING = 'vendit/attribute_mapping/attributes';

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly SerializerInterface $serializer,
    ) {
    }

    public function getMapping(): array
    {
        $value = $this->scopeConfig->getValue(self::XML_PATH_ATTRIBUTE_MAPPING);

        if (!$value) {
            return [];
        }

        try {
            $mapping = $this->serializer->unserialize($value);

            if (!is_array($mapping)) {
                return [];
            }

            // Convert to spec_name => attribute_code format
            $result = [];
            foreach ($mapping as $attribute) {
                if (
                    isset($attribute['spec_name']) &&
                    isset($attribute['attribute_code']) &&
                    !empty($attribute['spec_name']) &&
                    !empty($attribute['attribute_code'])
                ) {
                    $result[$attribute['spec_name']] = $attribute['attribute_code'];
                }
            }

            return $result;
        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * Check if a spec name is mapped to an attribute
     */
    public function isMapped(string $specName): bool
    {
        $mapping = $this->getMapping();
        return isset($mapping[$specName]);
    }

    /**
     * Get the attribute code for a spec name
     */
    public function getAttributeCode(string $specName): ?string
    {
        $mapping = $this->getMapping();
        return $mapping[$specName] ?? null;
    }
}
