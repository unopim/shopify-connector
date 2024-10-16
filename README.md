# UnoPim Shopify Connector

Easily integrate your Shopify store with UnoPim to manage and sync product data seamlessly.

## Installation

- Run the following command
```
composer require unopim/shopify-connector
```

### Register the shopify vite configuration

* Goto `config/unopim-vite.php` file and add following line under 'viters' keys
```php
        'shopify' => [
            'hot_file'                 => 'shopify-vite.hot',
            'build_directory'          => 'themes/shopify/build',
            'package_assets_directory' => 'src/Resources/assets',   
        ],
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
   "Webkul\\Shopify\\Tests\\": "packages/Webkul/Shopify/tests"
   ```

2. **Configure TestCase**  
   Open the `tests/Pest.php` file and add this line:

   ```php
   uses(Webkul\Shopify\Tests\ShopifyTestCase::class)->in('../packages/Webkul/Shopify/tests');
   ```

3. **Register the Test Suite**  
   In the `phpunit.xml` file, add the following lines for the test suite:

   ```xml
   <testsuite name="Shopify Feature Tests">
       <directory suffix="Test.php">./packages/Webkul/Shopify/tests/Feature</directory>
   </testsuite>
   ```

4. **Dump Composer Autoload for Tests**  
   ```bash
   composer dump-autoload
   ```

5. **Run Tests**  
   To run tests for the Shopify package, use the following command:

   ```bash
   ./vendor/bin/pest ./packages/Webkul/Shopify/tests/Feature
   ```

---
