# UnoPim Shopify Connector

Effortlessly integrate your Shopify store with UnoPim for seamless product data management and synchronization. You can currently export catalogs, including categories and both simple and variant products, from UnoPim to Shopify.

## Requiremenets:
* **Unopim**: v0.1.3

## Installation with composer

- Run the following command
```
composer require unopim/shopify-connector
```

* Run the command to execute migrations and clear the cache.

```bash
php artisan shopify-package:install;
php artisan optimize:clear;
```

## Running Test Cases with composer

1. **Register Test Directory**  
   In the `composer.json` file, register the test directory under the `autoload-dev` `psr-4` section:

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
## Installation without composer

Download and unzip the respective extension zip. Rename the folder to `Shopify` and move into the `packages/Webkul` directory of the project's root directory.

1. **Regsiter the package provider**
   In the `config/app.php` file add the below provider class under the `providers` key

   ```php
      Webkul\Shopify\Providers\ShopifyServiceProvider::class,
   ``` 
2. In the `composer.json` file register the test directory under the `autoload` `psr-4` section

   ```json
   "Webkul\\Shopify\\": "packages/Webkul/Shopify/src"
   ```
3. **Run below given commands**
   
   ```bash
   composer dump-autoload
   php artisan shopify-package:install
   php artisan optimize:clear
   ```

## Running test cases
1. **Register Test Directory**
   Register test directory in `composer.json` under the `autoload-dev` `psr-4` section

   ```json
   "Webkul\\Shopify\\Tests\\": "packages/Webkul/Shopify/tests"
   ```
2. **Configure TestCase**
   * Configure the testcase in `tests/Pest.php`. Add the following line:

   ```php
   uses(Webkul\Shopify\Tests\ShopifyTestCase::class)->in('../packages/Webkul/Shopify/tests');
   ```
3. **Dump Composer Autoload for Tests**  
   * Dump composer autolaod for tests directory

   ```bash
   composer dump-autoload;
   ```
4. **Run Tests**
   * Run tests for only this package with the below command

   ```bash
   ./vendor/bin/pest ./packages/Webkul/Shopify/tests/Feature
   ```
---
