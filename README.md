# Magento 2 Vendit Integration

Integration module for synchronizing products, stock, categories, customers, and orders between Vendit and Magento 2.

**Important note:** This module requires the [AmpersandHQ/magento2-disable-stock-reservation
](https://github.com/idhlagency/magento2-disable-stock-reservation) package.  
Stock reservations need to be disabled because inventory is managed in Vendit.

## Features

- **Stock Import**: Sync inventory levels from Vendit
- **Order Export**: Export orders to Vendit
- **Order Update Import**: Import order status updates from Vendit

## Upcoming features

These features are currently in development and thus disabled in the current version of this module:

- **Product Import**: Import products with variants, images, and attributes
- **Category Import**: Import and map product categories
- **Customer Import**: Sync customer data
- **Product Links**: Automatic import of related products and upsells

## Installation

```bash
# Install composer package
composer require reach-digital/magento2-vendit
# Setup module and generate directories
php bin/magento setup:upgrade
# Flush the cache
php bin/magento cache:flush
```

Make sure the 'Decrease Stock When Order is Placed' setting is enabled, by running:
```bash
./bin/magento config:set cataloginventory/options/can_subtract 1
```

Assign all product attributes to the 'Default' attribute set, since that is the only attribute set Vendit will use:
```bash
php bin/magento vendit:attributes:assign-to-default-set
```

Transform all attribute codes to lowercase, this is necessary to be able to correctly perform product imports and exports:
```bash
php bin/magento vendit:attribute-codes:lowercase
```

Trim all whitespaces around attribute options that are used as configurable attributes. This might be needed to prevent running into product import/export errors. Pass attribute codes as a comma separated list. By default, these attributes are `vendit_color` and `vendit_size`:
```bash
php bin/magento vendit:attribute-options:trim vendit_color,vendit_size
```

## Configuration

### Magento Configuration

Navigate to **Stores > Configuration > Vendit** to configure:

1. **General Settings**
   - Enable/disable the integration
   - Enable specific import/export features

2. **Directory Mapping**
   - Configure import/export file paths (relative to `var/` directory)
   - Set file prefixes for different import types
   - **Note:** these fields are all preconfigured and generally don't need any changes.

3. **Product Attribute Mapping**
   - Map Vendit size/color fields to Magento attributes
   - Set attributes that are required in Magento
   - Map other Vendit fields to Magento attributes
   - Configure barcode attribute for stock matching
   - Set tax class for imported products

4. **Order Status Mapping**
   - Map Vendit order statuses to Magento order statuses
   - **Note:** these fields are all preconfigured and generally don't need any changes.

### Vendit Configuration

**Note:** Prior to configuring Vendit, you need to setup an FTP account for Vendit to use.  
Use the directory as shown at **Vendit > General Configuration > Directory Mapping > Base Directory** in the Magento configuration as the home directory for your Vendit FTP account.

In the Vendit software, configure the following settings:

#### E-commerce Settings

- Set product export batch to 1000
- Set XML Export Encoding to UTF-8 (not UTF-16)
- Setup the SFTP credentials.
- Configure path mapping to match Magento's import directories:
  - Upload directory files: `/import/files/`
  - Upload directory images: `/import/images/`
  - Upload directory order status: `/import/orders/`
  - Download directory orders: `/export/orders/`

**Note**: The download directory for customers can be left empty, this is not supported yet.

## Usage

Cronjobs are configured to periodically sync the catalog and customer orders between Magento and Vendit, but there are commands available to manually trigger these processes as well:

### Import Commands

```bash
# Import products
php bin/magento vendit:products:import

# Import stock levels
php bin/magento vendit:stock:import

# Import categories
php bin/magento vendit:categories:import

# Import customers
php bin/magento vendit:customers:import

# Import order updates
php bin/magento vendit:order-updates:import
```

### Export Commands

```bash
# Export orders
php bin/magento vendit:orders:export
```

## Product Import Details

### Configurable Products

Products with multiple variations (different sizes/colors) are imported as configurable products:
- Parent product uses the `ProductNumber` as SKU
- Child products use the `ProductId` as SKU
- Variations are automatically linked based on size and color attributes

### Simple Products

Products with a single variation are imported as simple products:
- Uses the `ProductId` as SKU
- No configurable parent is created

### Product Links

- **Related Products**: Imported from `<SimilarProducts>` in the XML
- **Upsell Products**: Imported from `<Accessories>` in the XML

### Images

- Images are copied from the configured path at the 'Images Path' setting to the `pub/media/import/` directory.
- Images are assigned to both parent and child products
- Source images are automatically cleaned up after successful import
