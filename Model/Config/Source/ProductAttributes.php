<?php
/**
 * Copyright © Reach Digital (https://www.reachdigital.io/)
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace ReachDigital\Vendit\Model\Config\Source;

use Magento\Catalog\Api\ProductAttributeRepositoryInterface;
use Magento\Framework\Api\FilterBuilder;
use Magento\Framework\Api\Search\FilterGroupBuilder;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Api\SortOrderBuilder;
use Magento\Framework\Data\OptionSourceInterface;

class ProductAttributes implements OptionSourceInterface
{
    public function __construct(
        private readonly ProductAttributeRepositoryInterface $attributeRepository,
        private readonly SearchCriteriaBuilder $searchCriteriaBuilder,
        private readonly SortOrderBuilder $sortOrderBuilder,
        private readonly FilterGroupBuilder $filterGroupBuilder,
        private readonly FilterBuilder $filterBuilder,
    ) {
    }

    public function toOptionArray(): array
    {
        $staticFilter = $this->filterBuilder
            ->setField('backend_type')
            ->setConditionType('neq')
            ->setValue('static')
            ->create();
        $staticFilterGroup = $this->filterGroupBuilder->create()->setFilters([$staticFilter]);
        $sortOrder = $this->sortOrderBuilder
            ->setField('frontend_label')
            ->setDirection(\Laminas\Db\Sql\Select::ORDER_ASCENDING)
            ->create();

        $searchCriteria = $this->searchCriteriaBuilder
            ->create()
            ->setSortOrders([$sortOrder])
            ->setFilterGroups([$staticFilterGroup]);
        $attributes = $this->attributeRepository->getList($searchCriteria)->getItems();

        $options = [
            [
                'label' => __('-- Please Select --'),
                'value' => '',
            ],
        ];
        foreach ($attributes as $attribute) {
            if (!$attribute->getDefaultFrontendLabel()) {
                continue;
            }

            $options[] = [
                'value' => $attribute->getAttributeCode(),
                'label' => sprintf('%s (%s)', $attribute->getDefaultFrontendLabel(), $attribute->getAttributeCode()),
            ];
        }

        return $options;
    }
}
