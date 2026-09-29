<?php

namespace Webkul\Shopify\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Webkul\Attribute\Repositories\AttributeRepository;
use Webkul\Shopify\Database\Seeders\ShopifySettingConfigurationValuesSeeder;

#[Description('Install the Shopify package')]
#[Signature('shopify-package:install')]
class ShopifyInstaller extends Command
{
    public function __construct(protected AttributeRepository $attributeRepository)
    {
        parent::__construct();
    }

    public function handle(): void
    {
        $this->info('Installing Unopim Shopify connector...');

        if ($this->confirm('Would you like to run the migrations now?', true)) {
            $this->call('migrate');
            $this->call('db:seed', ['--class' => ShopifySettingConfigurationValuesSeeder::class]);
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
            'code'              => 'shopify_taxonomy',
            'type'              => 'shopify_taxonomy',
            'is_required'       => 0,
            'is_unique'         => 0,
            'value_per_locale'  => 0,
            'value_per_channel' => 0,
            'is_filterable'     => 0,
            'enable_wysiwyg'    => 0,
        ]);

        foreach (core()->getAllLocales() as $locale) {
            $attribute->translateOrNew($locale->code)->name = 'Shopify Taxonomy';
        }

        $attribute->save();

        $this->info('Created shopify_taxonomy attribute.');
    }
}
