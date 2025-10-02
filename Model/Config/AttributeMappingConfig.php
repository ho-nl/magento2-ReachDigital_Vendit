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
    private const XML_PATH_ATTRIBUTE_MAPPINGS = 'vendit/attribute_mapping/mappings';

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly SerializerInterface $serializer
    ) {
    }

    /**
     * Get attribute mappings as an associative array
     * Returns: ['spec_name' => 'attribute_code', ...]
     */
    public function getMappings(): array
    {
        $value = $this->scopeConfig->getValue(self::XML_PATH_ATTRIBUTE_MAPPINGS);

        if (!$value) {
            return [];
        }

        try {
            $mappings = $this->serializer->unserialize($value);

            if (!is_array($mappings)) {
                return [];
            }

            // Convert to spec_name => attribute_code format
            $result = [];
            foreach ($mappings as $mapping) {
                if (isset($mapping['spec_name']) && isset($mapping['attribute_code'])
                    && !empty($mapping['spec_name']) && !empty($mapping['attribute_code'])) {
                    $result[$mapping['spec_name']] = $mapping['attribute_code'];
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
        $mappings = $this->getMappings();
        return isset($mappings[$specName]);
    }

    /**
     * Get the attribute code for a spec name
     */
    public function getAttributeCode(string $specName): ?string
    {
        $mappings = $this->getMappings();
        return $mappings[$specName] ?? null;
    }
}
