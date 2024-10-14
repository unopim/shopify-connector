# UnoPim Shopify Connector

Easily integrate your Shopify store with UnoPim to manage and sync product data seamlessly.

## Installation

- Run the following command
```
composer require unopim/shopify-connector
```

- Run these commands below to complete the setup
```
composer dump-autoload
```

- Run these commands below to complete the setup
```
php artisan migrate
```
```
php artisan storage:link
```
```
php artisan optimize:clear
```
```
php artisan vendor:publish --all
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
