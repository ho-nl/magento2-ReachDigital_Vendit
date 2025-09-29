<?php
/**
 * Copyright © Reach Digital (https://www.reachdigital.io/)
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace ReachDigital\Vendit\Model;

use Magento\Customer\Api\GroupManagementInterface;
use Magento\CustomerImportExport\Model\Import\Address;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Filesystem;
use Magento\Framework\Filesystem\Directory\Write;
use Magento\ImportExport\Model\Import;
use Magento\ImportExport\Model\ImportFactory;
use Magento\ImportExport\Model\Import\Adapter;
use Magento\Store\Model\StoreManagerInterface;
use Zend_Locale_Data;

class ImportCustomersXml
{
    public function __construct(
        private readonly Config $config,
        private readonly ImportFactory $importModelFactory,
        private readonly Filesystem $filesystem,
        private readonly Address $importAddress,
        private readonly GroupManagementInterface $groupManagement,
        private readonly StoreManagerInterface $storeManager,
    ) {
    }

    public function execute(): void
    {
        $xmlFile = $this->config->getImportFilePath(Config::TYPE_CUSTOMER);

        if (!file_exists($xmlFile)) {
            throw new \Exception(sprintf('Customer import XML file not found (%s)', $xmlFile));
        }
        $xml = simplexml_load_file($xmlFile);

        $customers = [];
        $addresses = [];
        foreach ($xml->Customers->Customer as $customerNode) {
            $customerData = $this->mapCustomer($customerNode);
            $customers[] = $customerData;

            // Collect addresses for this customer
            if (isset($customerNode->Addresses)) {
                foreach ($customerNode->Addresses->Address as $addressNode) {
                    $adressData = $this->mapAddress($customerData['email'], $addressNode);
                    if (is_null($adressData)) {
                        continue;
                    }

                    $addresses[] = $adressData;
                }
            }
        }

        $customerCsvFilePath = $this->generateCsv($customers, 'customers_import.csv');
        $addressCsvFilePath = $this->generateCsv($addresses, 'customer_addresses_import.csv');

        try {
            $this->importCsv($customerCsvFilePath, 'customer');
        } catch (\Exception $e) {
            throw new \Exception('Customer import failed: ' . $e->getMessage());
        }

        try {
            $this->importCsv($addressCsvFilePath, $this->importAddress->getEntityTypeCode());
        } catch (\Exception $e) {
            throw new \Exception('Customer address import failed: ' . $e->getMessage());
        }
    }

    public function generateCsv($rows, $filename): string
    {
        // Find all unique headers across all rows
        $allHeaders = [];
        foreach ($rows as $row) {
            foreach (array_keys($row) as $key) {
                $allHeaders[$key] = true;
            }
        }
        $headers = array_keys($allHeaders);

        $csvRows[] = $headers;
        foreach ($rows as $row) {
            $fullRow = [];
            foreach ($headers as $header) {
                $fullRow[] = array_key_exists($header, $row) ? $row[$header] : '';
            }
            $csvRows[] = $fullRow;
        }

        $csvFilePath = BP . '/var/importexport/' . $filename;
        $fp = fopen($csvFilePath, 'w');
        foreach ($csvRows as $row) {
            fputcsv($fp, $row);
        }
        fclose($fp);

        return $csvFilePath;
    }

    public function importCsv($csvFilePath, $entityTypeCode): void
    {
        /** @var Write $write */
        $write = $this->filesystem->getDirectoryWrite(DirectoryList::ROOT);
        $sourceAdapter = Adapter::findAdapterFor(
            $csvFilePath,
            $write,
            ','
        );

        $import = $this->importModelFactory->create()->setData([
            'entity' => $entityTypeCode,
            'behavior' => Import::BEHAVIOR_ADD_UPDATE,
            'csv_data' => file_get_contents($csvFilePath),
            'validation_strategy' => 'validation-stop-on-errors',
            'allowed_error_count' => 0,
        ]);

        $validationResult = $import->validateSource($sourceAdapter);

        if (!$validationResult) {
            $errorAggregator = $import->getErrorAggregator();
            $errorMessages = [];
            foreach ($errorAggregator->getAllErrors() as $error) {
                $errorMessages[] = $error->getErrorMessage();
            }
            throw new \Exception(sprintf(
                'Customer import "%s" validation failed with %s errors: %s',
                $entityTypeCode,
                count($errorMessages),
                implode(', ', $errorMessages))
            );
        }

        $errorAggregator = $import->getErrorAggregator();
        $errorAggregator->initValidationStrategy(
            $import->getData(Import::FIELD_NAME_VALIDATION_STRATEGY),
            $import->getData(Import::FIELD_NAME_ALLOWED_ERROR_COUNT)
        );

        $import->importSource();
    }

    public function mapCustomer($customerNode): array
    {
        $defaultStore = $this->storeManager->getDefaultStoreView();
        $defaultWebsite = $this->storeManager->getWebsite($defaultStore->getWebsiteId());
        $defaultGroup = $this->groupManagement->getDefaultGroup();

        // Extract contact info from first address's default contact
        $email = $firstname = $middlename = $lastname = '';

        if (isset($customerNode->Addresses->Address)) {
            foreach ($customerNode->Addresses->Address as $addressNode) {
                if (isset($addressNode->Contacts->Contact)) {
                    foreach ($addressNode->Contacts->Contact as $contactNode) {
                        if ((string) $contactNode->DefaultContact === 'true') {
                            $email = (string) $contactNode->EmailAddress;
                            // Firstname and lastname fallbacks to email if not present; they can't be empty
                            $firstname = (string) ($contactNode->FirstName != '' ? $contactNode->FirstName : $contactNode->EmailAddress);
                            $middlename = (string) $contactNode->MiddleName;
                            $lastname = (string) ($contactNode->LastName != '' ? $contactNode->LastName : $contactNode->EmailAddress);
                            break 2;
                        }
                    }
                }
            }
        }

        return [
            '_website' => $defaultWebsite->getCode(),
            '_store' => $defaultStore->getCode(),
            'group_id' => $defaultGroup->getId(),
            'email' => $email,
            'firstname' => $firstname,
            'middlename' => $middlename,
            'lastname' => $lastname,
        ];
    }

    public function mapAddress($emailAddress, \SimpleXMLElement $addressNode): ?array
    {
        if ($addressNode->Street == '') {
            // Skip addresses without a street
            return null;
        }

        $defaultStore = $this->storeManager->getDefaultStoreView();
        $defaultWebsite = $this->storeManager->getWebsite($defaultStore->getWebsiteId());

        $firstname = (string) $addressNode?->Contacts?->Contact?->FirstName;
        $lastname = (string) $addressNode?->Contacts?->Contact?->LastName;
        $telephone = $addressNode?->Contacts?->Contact?->Phones?->Phone?->PhoneNumber;

        return [
            '_website' => $defaultWebsite->getCode(),
            '_email' => $emailAddress,
            // Entity ID is mandatory, but when left empty, address will be matched by customer email
            '_entity_id' => '',
            // Firstname and lastname fallbacks to email if not present; they can't be empty
            'firstname' => (string) ($firstname != '' ? $firstname : $emailAddress),
            'middlename' => (string) $addressNode->Contacts->Contact->MiddleName,
            'lastname' => (string) ($lastname != '' ? $lastname : $emailAddress),
            'street' => (string) $addressNode->Street
                . (!empty($addressNode->HouseNumber) ? ' ' . $addressNode->HouseNumber : '')
                . (!empty($addressNode->HouseNumberSuffix) ? ' ' . $addressNode->HouseNumberSuffix : ''),
            'city' => (string) $addressNode->City,
            'postcode' => (string) $addressNode->ZipCode,
            'country_id' => $this->getCountryCode((string) $addressNode->Country),
            'telephone' => (string) $telephone != '' ? (string) $telephone : '0000000000',
            '_address_default_billing_' => ((string) $addressNode->DefaultAddress === 'true') ? 1 : 0,
            '_address_default_shipping_' => ((string) $addressNode->DefaultAddress === 'true') ? 1 : 0,
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
