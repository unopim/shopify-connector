# UnoPim Shopify Connector

Effortlessly integrate your Shopify store with UnoPim for seamless product data management and synchronization. You can currently export catalogs, including categories and both simple and variant products, from UnoPim to Shopify.

**Note:** This package requires UnoPim version 0.1.3 or greater.

## Installation

- Run the following command
```
composer require unopim/shopify-connector
```

* Run the command to execute migrations and clear the cache.

```bash
php artisan shopify-package:install;
php artisan optimize:clear;
```

## Running Test Cases

1. **Register Test Directory**  
   In the `composer.json` file, register the test directory under the `autoload-dev` `psr` section:

   ```json
   "Webkul\\Shopify\\Tests\\": "vendor/unopim/shopify-connector/tests/"
   ```

2. **Configure TestCase**  
   Open the `tests/Pest.php` file and add this line:

   ```php
   uses(Webkul\Shopify\Tests\ShopifyTestCase::class)->in('../vendor/unopim/shopify-connector/tests');
   ```

3. **Dump Composer Autoload for Tests**  
   ```bash
   composer dump-autoload
   ```

4. **Run Tests**  
   To run tests for the Shopify package, use the following command:

   ```bash
   ./vendor/bin/pest ./vendor/unopim/shopify-connector/tests
   ```

---
