<?php
/**
 * Copyright © Reach Digital (https://www.reachdigital.io/)
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace ReachDigital\Vendit\Block\Adminhtml\Form\Field;

use Magento\Catalog\Api\ProductAttributeRepositoryInterface;
use Magento\Framework\Api\FilterBuilder;
use Magento\Framework\Api\Search\FilterGroupBuilder;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Api\SortOrderBuilder;
use Magento\Framework\View\Element\Html\Select;

class ProductAttributeColumn extends Select
{
    public function __construct(
        \Magento\Framework\View\Element\Context $context,
        private readonly ProductAttributeRepositoryInterface $attributeRepository,
        private readonly SearchCriteriaBuilder $searchCriteriaBuilder,
        private readonly SortOrderBuilder $sortOrderBuilder,
        private readonly FilterGroupBuilder $filterGroupBuilder,
        private readonly FilterBuilder $filterBuilder,
        array $data = [],
    ) {
        parent::__construct($context, $data);
    }

    public function setInputName($value)
    {
        return $this->setName($value);
    }

    public function setInputId($value)
    {
        return $this->setId($value);
    }

    public function _toHtml(): string
    {
        if (!$this->getOptions()) {
            $this->setOptions($this->getSourceOptions());
        }

        return parent::_toHtml();
    }

    public function getSourceOptions(): array
    {
        $staticFilter = $this->filterBuilder
            ->setField('backend_type')
            ->setConditionType('neq')
            ->setValue('static')
            ->create();
        $staticFilterGroup = $this->filterGroupBuilder->create()->setFilters([$staticFilter]);
        $userDefinedFilter = $this->filterBuilder
            ->setField('is_user_defined')
            ->setValue('1')
            ->create();
        $userDefinedFilterGroup = $this->filterGroupBuilder->create()->setFilters([$userDefinedFilter]);
        $sortOrder = $this->sortOrderBuilder
            ->setField('frontend_label')
            ->setDirection(\Laminas\Db\Sql\Select::ORDER_ASCENDING)
            ->create();

        $searchCriteria = $this->searchCriteriaBuilder
            ->create()
            ->setSortOrders([$sortOrder])
            ->setFilterGroups([$staticFilterGroup, $userDefinedFilterGroup]);
        $attributes = $this->attributeRepository->getList($searchCriteria)->getItems();

        $options = [
            [
                'label' => __('-- Please Select --'),
                'value' => '',
            ],
        ];
        foreach ($attributes as $attribute) {
            $options[] = [
                'value' => $attribute->getAttributeCode(),
                'label' => sprintf('%s (%s)', $attribute->getDefaultFrontendLabel(), $attribute->getAttributeCode()),
            ];
        }

        return $options;
    }
}
