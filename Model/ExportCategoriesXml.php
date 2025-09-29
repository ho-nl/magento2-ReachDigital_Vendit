<?php
/**
 * Copyright © Reach Digital (https://www.reachdigital.io/)
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace ReachDigital\Vendit\Model;

use Magento\Catalog\Model\CategoryRepository;
use Magento\Framework\Stdlib\DateTime\DateTime;

class ExportCategoriesXml
{
    const ROOT_CATEGORY_ID = 2;

    public function __construct(
        public CategoryRepository $categoryRepo,
        public DateTime $dateTime,
        public Config $venditConfig
    ) {
    }

    public function execute(): void
    {
        $doc = new \DOMDocument('1.0', 'utf-16');
        $doc->formatOutput = true;

        $groupExport = $doc->createElement('GroupExport');
        $groupExport->setAttribute('xmlns:xsi', 'http://www.w3.org/2001/XMLSchema-instance');
        $groupExport->setAttribute('xmlns:xsd', 'http://www.w3.org/2001/XMLSchema');
        $doc->appendChild($groupExport);

        $info = $doc->createElement('ExportInfo');
        $groupExport->appendChild($info);
        $info->appendChild($doc->createElement('ExportDateTime', $this->dateTime->gmtDate(DATE_ATOM)));
        $info->appendChild($doc->createElement('Type', 'Incremental'));
        $info->appendChild($doc->createElement('ExportStarted', 'Manual'));

        $groups = $doc->createElement('Groups');
        $groupExport->appendChild($groups);

        $rootCategory = $this->categoryRepo->get(self::ROOT_CATEGORY_ID);
        $this->addCategoryToXml($doc, $groups, $rootCategory, '00000000-0000-0000-0000-000000000000');

        $doc->save($this->getFilePath());
    }

    public function addCategoryToXml(\DOMDocument $doc, \DOMElement $parentNode, $category, $parentGuid): void
    {
        if (!$category->getIsActive()) {
            return;
        }

        $groupNode = $doc->createElement('Group');
        $parentNode->appendChild($groupNode);

        $guid = $this->getGuid((string) $category->getId());

        $elements = [
            'GroupGuid' => $guid,
            'GroupName' => $category->getName(),
            'GroupMetaTitle' => $category->getMetaTitle() ?: '',
            'GroupMetaKeywords' => $category->getMetaKeywords() ?: '',
            'GroupMetaDescription' => $category->getMetaDescription() ?: '',
            'GroupUrlName' => $category->getUrlKey(),
            'ItemOrder' => (string) $category->getPosition(),
            'Parent_Guid' => $parentGuid,
        ];

        foreach ($elements as $name => $value) {
            $groupNode->appendChild($doc->createElement($name, htmlspecialchars($value)));
        }

        // Handle category description as GroupDescription with CDATA
        $descriptionNode = $doc->createElement('GroupDescription');
        $descriptionCdata = $doc->createCDATASection($category->getDescription() ?: $category->getName());
        $descriptionNode->appendChild($descriptionCdata);
        $groupNode->appendChild($descriptionNode);

        // Handle child categories as SubGroups
        $children = $category->getChildrenCategories();
        if (count($children)) {
            $subGroups = $doc->createElement('SubGroups');
            $groupNode->appendChild($subGroups);
            foreach ($children as $child) {
                $childCategory = $this->categoryRepo->get($child->getId());
                $this->addCategoryToXml($doc, $subGroups, $childCategory, $guid);
            }
        }
    }

    public function getFilePath(): string
    {
        return $this->venditConfig->getExportFilePath(Config::TYPE_CATEGORY);
    }

    public function getGuid(string $categoryId): string
    {
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(md5($categoryId), 4));
    }
}
