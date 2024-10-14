# UnoPim Shopify Connector

Easily integrate your Shopify store with UnoPim to manage and sync product data seamlessly.

## Installation

1. **Unzip the Extension**  
   Unzip the provided extension file and merge the `packages` folder into your project root directory.

2. **Register the Package Provider**  
   Open the `config/app.php` file and add the following line under the `'providers'` section:

   ```php
   Webkul\Shopify\Providers\ShopifyServiceProvider::class,
   ```

3. **Autoload the Package**  
   In the `composer.json` file, add the following line under the `'psr-4'` section:

   ```json
   "Webkul\\Shopify\\": "packages/Webkul/Shopify/src"
   ```

## Vite Configuration

To configure Vite for Shopify, go to the `config/unopim-vite.php` file and add the following under the `'viters'` key:

```php
'shopify' => [
    'hot_file'                 => 'shopify-vite.hot',
    'build_directory'          => 'themes/shopify/build',
    'package_assets_directory' => 'src/Resources/assets',   
],
```

## Command Setup

After completing the above steps, run the following commands:

1. **Dump Composer Autoload**  
   ```bash
   composer dump-autoload
   ```

2. **Install Shopify Plugin**  
   ```bash
   php artisan shopify-package:install
   ```

3. **Clear Application Cache**  
   ```bash
   php artisan optimize:clear
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