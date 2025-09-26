<?php
/**
 * Copyright © Reach Digital (https://www.reachdigital.io/)
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace ReachDigital\Vendit\Model;

use Magento\Customer\Api\GroupManagementInterface;
use Magento\CustomerImportExport\Model\Import\CustomerComposite;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Filesystem;
use Magento\Framework\Filesystem\Directory\Write;
use Magento\ImportExport\Model\Import;
use Magento\ImportExport\Model\Import\Adapter;
use Magento\Store\Model\StoreManagerInterface;
use Zend_Locale_Data;

class ImportCustomersXml
{
    const FILENAME = 'Customers.xml';

    public function __construct(
        private readonly Config $config,
        private readonly Import $importModel,
        private readonly Filesystem $filesystem,
        private readonly CustomerComposite $customerComposite,
        private readonly GroupManagementInterface $groupManagement,
        private readonly StoreManagerInterface $storeManager,
    ) {
    }

    public function execute(): void
    {
        $xmlFile = $this->config->getImportFilePath(self::FILENAME, Config::TYPE_CUSTOMER);

        if (!file_exists($xmlFile)) {
            throw new \Exception(sprintf('Customer import XML file not found (%s)', self::FILENAME));
        }
        $xml = simplexml_load_file($xmlFile);

        $customers = [];
        foreach ($xml->Customers->Customer as $customerNode) {
            $customers[] = $this->mapCustomer($customerNode);
        }

        $csvFilePath = $this->generateCsv($customers);

        try {
            $this->importCsv($csvFilePath);
        } catch (\Exception $e) {
            throw new \Exception('Customer import failed: ' . $e->getMessage());
        }
    }

    public function generateCsv($customers): string
    {
        // Find all unique headers across all customers
        $allHeaders = [];
        foreach ($customers as $row) {
            foreach (array_keys($row) as $key) {
                $allHeaders[$key] = true;
            }
        }
        $headers = array_keys($allHeaders);

        $csvRows[] = $headers;
        foreach ($customers as $row) {
            $fullRow = [];
            foreach ($headers as $header) {
                $fullRow[] = array_key_exists($header, $row) ? $row[$header] : '';
            }
            $csvRows[] = $fullRow;
        }

        $csvFilePath = BP . '/var/importexport/customers_import.csv';
        $fp = fopen($csvFilePath, 'w');
        foreach ($csvRows as $row) {
            fputcsv($fp, $row);
        }
        fclose($fp);

        return $csvFilePath;
    }

    /**
     * @throws \Magento\Framework\Exception\FileSystemException
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function importCsv($csvFilePath): void
    {
        /** @var Write $write */
        $write = $this->filesystem->getDirectoryWrite(DirectoryList::ROOT);
        $sourceAdapter = Adapter::findAdapterFor(
            $csvFilePath,
            $write,
            ','
        );

        // Use Magento's import model with CSV data
        $this->importModel->setData([
            'entity' => $this->customerComposite->getEntityTypeCode(),
            'behavior' => Import::BEHAVIOR_ADD_UPDATE,
            'csv_data' => file_get_contents($csvFilePath),
            'validation_strategy' => 'validation-stop-on-errors',
            'allowed_error_count' => 0,
        ]);

        $validationResult = $this->importModel->validateSource($sourceAdapter);

        if (!$validationResult) {
            $errorAggregator = $this->importModel->getErrorAggregator();
            $errorMessages = [];
            foreach ($errorAggregator->getAllErrors() as $error) {
                $errorMessages[] = $error->getErrorMessage();
            }
            throw new \Exception(sprintf(
                'Customer import validation failed with %s errors: %s',
                count($errorMessages),
                implode(', ', $errorMessages))
            );
        }

        $errorAggregator = $this->importModel->getErrorAggregator();
        $errorAggregator->initValidationStrategy(
            $this->importModel->getData(Import::FIELD_NAME_VALIDATION_STRATEGY),
            $this->importModel->getData(Import::FIELD_NAME_ALLOWED_ERROR_COUNT)
        );

        $this->importModel->importSource();
    }

    public function mapCustomer($customerNode): array
    {
        $defaultStore = $this->storeManager->getDefaultStoreView();
        $defaultWebsite = $this->storeManager->getWebsite($defaultStore->getWebsiteId());
        $defaultGroup = $this->groupManagement->getDefaultGroup();

        return [
            // Customer
            '_website' => $defaultWebsite->getCode(),
            '_store' => $defaultStore->getCode(),
            'group_id' => $defaultGroup->getId(),
            'email' => (string) $customerNode->eMailAddress,
            'firstname' => (string) $customerNode->Firstname,
            'middlename' => (string) $customerNode->Middlename,
            'lastname' => (string) $customerNode->Lastname,
            'dob' => (string) $customerNode->Birthdate,
            // Address
            '_address_city' => (string) $customerNode->City,
            '_address_country_id' => $this->getCountryCode((string) $customerNode->Country),
            '_address_firstname' => (string) $customerNode->Firstname,
            '_address_middlename' => (string) $customerNode->Middlename,
            '_address_lastname' => (string) $customerNode->Lastname,
            '_address_postcode' => (string) $customerNode->ZipCode,
            '_address_street' => $customerNode->Street
                . (!empty($customerNode->HouseNumber) ? ' ' . $customerNode->HouseNumber : '')
                . (!empty($customerNode->HouseNumberSuffix) ? ' ' . $customerNode->HouseNumberSuffix : ''),
            '_address_telephone' => (string) $customerNode->PhoneNumber,
        ];
    }

    private function getCountryCode(string $countryName): string
    {
        $locale = 'nl_NL';
        $countries = Zend_Locale_Data::getList($locale, 'territory');

        if (!array_search($countryName, $countries)) {
            throw new \Exception(sprintf('Country code not found for country "%s"', $countryName));
        }

        return array_search($countryName, $countries);
    }
}
