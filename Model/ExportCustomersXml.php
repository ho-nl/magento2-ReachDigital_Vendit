<?php
/**
 * Copyright © Reach Digital (https://www.reachdigital.io/)
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace ReachDigital\Vendit\Model;

use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Api\Data\CustomerInterface;
use Magento\Customer\Api\Data\AddressInterface;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Magento\Framework\FlagManager;
use Magento\Directory\Model\CountryFactory;
use Magento\Framework\App\ResourceConnection;

class ExportCustomersXml
{
    private const LAST_EXPORT_FLAG_CODE = 'vendit_customer_export_last_run';

    public function __construct(
        private readonly CustomerRepositoryInterface $customerRepository,
        private readonly DateTime $dateTime,
        private readonly Config $venditConfig,
        private readonly FlagManager $flagManager,
        private readonly CountryFactory $countryFactory,
        private readonly ResourceConnection $resourceConnection,
    ) {
    }

    public function execute(): int
    {
        // Get last export timestamp
        $lastExportTime = $this->getLastExportTimestamp();

        // Get customer IDs that have been updated (customer OR their addresses)
        $customerIds = $this->getUpdatedCustomerIds($lastExportTime);

        $exportTimestamp = $this->dateTime->gmtDate();
        $i = 0;

        foreach ($customerIds as $customerId) {
            try {
                $customer = $this->customerRepository->getById($customerId);

                // Create separate XML file for each customer
                $this->exportSingleCustomer($customer, $exportTimestamp);

                $i++;
            } catch (\Exception $e) {
                // Skip customer if it can't be loaded
                continue;
            }
        }

        // Update last export timestamp
        $this->saveLastExportTimestamp($exportTimestamp);

        return $i;
    }

    private function exportSingleCustomer(CustomerInterface $customer, string $exportTimestamp): void
    {
        $doc = new \DOMDocument('1.0', 'utf-16');
        $doc->formatOutput = true;

        $customerImport = $doc->createElement('CustomerImport');
        $customerImport->setAttribute('xmlns:xsi', 'http://www.w3.org/2001/XMLSchema-instance');
        $customerImport->setAttribute('xmlns:xsd', 'http://www.w3.org/2001/XMLSchema');
        $doc->appendChild($customerImport);

        $info = $doc->createElement('ImportInfo');
        $customerImport->appendChild($info);
        $info->appendChild($doc->createElement('ExportDateTime', $exportTimestamp));

        $customers = $doc->createElement('Customers');
        $customerImport->appendChild($customers);

        // Export customer as XML
        $this->addCustomerToXml($doc, $customers, $customer);

        // Generate filename with vendit customer number and timestamp
        $filename = $this->generateFilename($customer, $exportTimestamp);
        $filePath = $this->getExportDirectory() . DIRECTORY_SEPARATOR . $filename;

        $doc->save($filePath);
    }

    private function addCustomerToXml(\DOMDocument $doc, \DOMElement $parentNode, CustomerInterface $customer): void
    {
        $customerNode = $doc->createElement('Customer');
        $parentNode->appendChild($customerNode);

        // Get default billing address
        $billingAddress = null;
        if ($customer->getDefaultBilling()) {
            $billingAddress = $this->getAddressById($customer, (int) $customer->getDefaultBilling());
        }

        $venditCustomerNumber = $customer->getCustomAttribute('vendit_customer_number')
            ? $customer->getCustomAttribute('vendit_customer_number')->getValue()
            : '';
        $customerNode->appendChild(
            $doc->createElement('CustomerNumber', htmlspecialchars($venditCustomerNumber, ENT_XML1)),
        );
        $customerNode->appendChild(
            $doc->createElement('CreationDateTime', $this->formatDateTime($customer->getCreatedAt())),
        );
        $customerNode->appendChild($doc->createElement('TransactionsBlocked', 'false'));

        // Customer name fields from billing address (or customer entity)
        $customerNode->appendChild(
            $doc->createElement(
                'Title',
                htmlspecialchars($billingAddress ? $billingAddress->getPrefix() ?? '' : '', ENT_XML1),
            ),
        );
        $customerNode->appendChild(
            $doc->createElement('Lastname', htmlspecialchars($customer->getLastname(), ENT_XML1)),
        );
        $customerNode->appendChild(
            $doc->createElement('Middlename', htmlspecialchars($customer->getMiddlename() ?? '', ENT_XML1)),
        );
        $customerNode->appendChild(
            $doc->createElement('Firstname', htmlspecialchars($customer->getFirstname(), ENT_XML1)),
        );

        // Sex/Gender
        $gender = 'Man';
        if ($customer->getGender()) {
            $gender = $customer->getGender() == 2 ? 'Vrouw' : 'Man';
        }
        $customerNode->appendChild($doc->createElement('Sex', $gender));

        // Birthdate
        $birthdate = $customer->getDob() ? $this->formatDateTime($customer->getDob()) : '';
        $customerNode->appendChild($doc->createElement('Birthdate', htmlspecialchars($birthdate, ENT_XML1)));

        // Contact information
        $customerNode->appendChild(
            $doc->createElement('eMailAddress', htmlspecialchars($customer->getEmail(), ENT_XML1)),
        );
        $customerNode->appendChild(
            $doc->createElement(
                'PhoneNumber',
                htmlspecialchars($billingAddress ? $billingAddress->getTelephone() ?? '' : '', ENT_XML1),
            ),
        );
        $customerNode->appendChild($doc->createElement('MobileNumber', ''));

        // Company information
        $companyName = $billingAddress && $billingAddress->getCompany() ? $billingAddress->getCompany() : '';
        $customerNode->appendChild($doc->createElement('CompanyName', htmlspecialchars($companyName, ENT_XML1)));
        $customerNode->appendChild($doc->createElement('KVKNumber', ''));

        $vatId = $customer->getTaxvat() ?? '';
        $customerNode->appendChild($doc->createElement('VATNumber', htmlspecialchars($vatId, ENT_XML1)));

        // Address fields from default billing address
        if ($billingAddress) {
            $street = $billingAddress->getStreet();
            $streetName = $street[0] ?? '';
            $houseNumber = $street[1] ?? '';
            $houseNumberSuffix = $street[2] ?? '';

            $customerNode->appendChild($doc->createElement('Street', htmlspecialchars($streetName, ENT_XML1)));
            $customerNode->appendChild(
                $doc->createElement('ZipCode', htmlspecialchars($billingAddress->getPostcode() ?? '', ENT_XML1)),
            );
            $customerNode->appendChild($doc->createElement('HouseNumber', htmlspecialchars($houseNumber, ENT_XML1)));
            $customerNode->appendChild(
                $doc->createElement('HouseNumberSuffix', htmlspecialchars($houseNumberSuffix, ENT_XML1)),
            );
            $customerNode->appendChild(
                $doc->createElement('City', htmlspecialchars($billingAddress->getCity() ?? '', ENT_XML1)),
            );
            $countryName = $this->getCountryName($billingAddress->getCountryId());
            $customerNode->appendChild($doc->createElement('Country', htmlspecialchars($countryName, ENT_XML1)));
        } else {
            $customerNode->appendChild($doc->createElement('Street', ''));
            $customerNode->appendChild($doc->createElement('ZipCode', ''));
            $customerNode->appendChild($doc->createElement('HouseNumber', ''));
            $customerNode->appendChild($doc->createElement('HouseNumberSuffix', ''));
            $customerNode->appendChild($doc->createElement('City', ''));
            $customerNode->appendChild($doc->createElement('Country', ''));
        }

        $customerNode->appendChild($doc->createElement('IBAN', ''));
    }

    private function getAddressById(CustomerInterface $customer, int $addressId): ?AddressInterface
    {
        foreach ($customer->getAddresses() as $address) {
            if ($address->getId() == $addressId) {
                return $address;
            }
        }
        return null;
    }

    private function getCountryName(?string $countryId): string
    {
        if (!$countryId) {
            return '';
        }

        try {
            $country = $this->countryFactory->create()->loadByCode($countryId);
            return $country->getName() ?? $countryId;
        } catch (\Exception $e) {
            return $countryId;
        }
    }

    private function formatDateTime(?string $dateTime): string
    {
        if (!$dateTime) {
            return '';
        }

        try {
            $date = new \DateTime($dateTime);
            return $date->format('Y-m-d\TH:i:s');
        } catch (\Exception $e) {
            return $dateTime;
        }
    }

    private function getLastExportTimestamp(): ?string
    {
        $timestamp = $this->flagManager->getFlagData(self::LAST_EXPORT_FLAG_CODE);
        return $timestamp ? (string) $timestamp : null;
    }

    private function saveLastExportTimestamp(string $timestamp): void
    {
        $this->flagManager->saveFlag(self::LAST_EXPORT_FLAG_CODE, $timestamp);
    }

    /**
     * Get customer IDs that have been updated since last export
     * This includes customers whose entity was updated OR whose addresses were updated
     *
     * @param string|null $lastExportTime
     * @return array
     */
    private function getUpdatedCustomerIds(?string $lastExportTime): array
    {
        $connection = $this->resourceConnection->getConnection();

        if (!$lastExportTime) {
            // First run - export all customers
            $select = $connection
                ->select()
                ->from(['ce' => $connection->getTableName('customer_entity')], ['entity_id'])
                ->order('ce.entity_id ASC');

            return $connection->fetchCol($select);
        }

        // Get customers updated since last export
        $customerSelect = $connection
            ->select()
            ->from(['ce' => $connection->getTableName('customer_entity')], ['entity_id'])
            ->where('ce.updated_at > ?', $lastExportTime);

        // Get customers whose addresses were updated since last export
        $addressSelect = $connection
            ->select()
            ->from(['cae' => $connection->getTableName('customer_address_entity')], ['parent_id'])
            ->where('cae.updated_at > ?', $lastExportTime)
            ->group('cae.parent_id');

        // Union both queries to get unique customer IDs
        $unionSelect = $connection
            ->select()
            ->union([$customerSelect, $addressSelect], \Magento\Framework\DB\Select::SQL_UNION)
            ->order('entity_id ASC');

        return $connection->fetchCol($unionSelect);
    }

    private function generateFilename(CustomerInterface $customer, string $exportTimestamp): string
    {
        $venditCustomerNumber = $customer->getCustomAttribute('vendit_customer_number')
            ? $customer->getCustomAttribute('vendit_customer_number')->getValue()
            : '';
        $timestamp = (new \DateTime($exportTimestamp))->format('YmdHis');

        // Get filename template from config (e.g., "Customer_%s.xml")
        $filenameTemplate = basename($this->venditConfig->getCustomerExportFilePath());

        // Replace %s placeholders with customer number and timestamp
        // If customer has vendit number: Customer_2323_20250130120000.xml
        // Otherwise: Customer_20250130120000.xml
        $replacement = $venditCustomerNumber ? $venditCustomerNumber . '_' . $timestamp : $timestamp;

        return sprintf($filenameTemplate, $replacement);
    }

    private function getExportDirectory(): string
    {
        $filePath = $this->venditConfig->getCustomerExportFilePath();
        return dirname($filePath);
    }
}
