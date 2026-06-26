<?php

namespace Webkul\Shopify\Console\Commands;

use Illuminate\Console\Command;
use Webkul\Attribute\Repositories\AttributeRepository;

class ShopifyInstaller extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'shopify-package:install';

    protected $description = 'Install the Shopify package';

    public function __construct(protected AttributeRepository $attributeRepository)
    {
        parent::__construct();
    }

    public function handle()
    {
        $this->info('Installing Unopim Shopify connector...');

        if ($this->confirm('Would you like to run the migrations now?', true)) {
            $this->call('migrate');
            $this->call('db:seed', ['--class' => 'Webkul\Shopify\Database\Seeders\ShopifySettingConfigurationValuesSeeder']);
        }

        $this->call('vendor:publish', [
            '--tag' => 'shopify-config',
        ]);

        $this->createTaxonomyAttribute();

        $this->info('Unopim Shopify connector installed successfully!');
    }

    protected function createTaxonomyAttribute(): void
    {
        if ($this->attributeRepository->findOneByField('code', 'shopify_taxonomy')) {
            return;
        }

        $attribute = $this->attributeRepository->create([
            'code' => 'shopify_taxonomy',
            'type' => 'shopify_taxonomy',
            'is_required' => 0,
            'is_unique' => 0,
            'value_per_locale' => 0,
            'value_per_channel' => 0,
            'is_filterable' => 0,
            'enable_wysiwyg' => 0,
        ]);

        foreach (core()->getAllLocales() as $locale) {
            $attribute->translateOrNew($locale->code)->name = 'Shopify Taxonomy';
        }

        $attribute->save();

        $this->info('Created shopify_taxonomy attribute.');
    }
}
