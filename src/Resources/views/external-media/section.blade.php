@if (request()->routeIs('admin.shopify.export-mappings'))
    @php($mediaMappingTitle = trans('shopify::app.shopify.export.mapping.images.title'))

    <v-shopify-pro-external-media-mapping></v-shopify-pro-external-media-mapping>

    @pushOnce('scripts')
        <script type="text/x-template" id="v-shopify-pro-external-media-mapping-template">
            <fieldset @disabled(! $shopifyProInstalled) class="bg-white dark:bg-cherry-900 rounded box-shadow block">
                <x-shopify::pro-notice
                    :title="trans('shopify::app.shopify.external-media.title')"
                    :note="trans('shopify::app.shopify.pro.media-note')"
                />

                @foreach ([
                    'external_image_attribute' => ['label' => 'image', 'value' => $externalImageAttribute],
                    'external_video_attribute' => ['label' => 'video', 'value' => $externalVideoAttribute],
                ] as $fieldName => $field)
                    <div class="shopify-map-row grid grid-cols-2 gap-2.5 items-center px-4 py-4 text-gray-600 dark:text-gray-300 @unless ($loop->last) border-b dark:border-cherry-800 @endunless">
                        <div>
                            <p class="break-words">@lang('shopify::app.shopify.external-media.'.$field['label'])</p>

                            <p class="break-words mt-1 text-xs text-gray-500 dark:text-gray-400">@lang('shopify::app.shopify.external-media.'.$field['label'].'-info')</p>
                        </div>

                        <x-admin::form.control-group class="!mb-0">
                            <x-admin::form.control-group.control
                                type="select"
                                :name="$fieldName"
                                :value="$field['value']"
                                :options="json_encode($urlAttributes)"
                                track-by="code"
                                label-by="label"
                                :label="trans('shopify::app.shopify.external-media.'.$field['label'])"
                                :placeholder="trans('shopify::app.shopify.external-media.'.$field['label'])"
                            />

                            <x-admin::form.control-group.error :control-name="$fieldName" />
                        </x-admin::form.control-group>
                    </div>
                @endforeach
            </fieldset>
        </script>

        <script type="module">
            app.component('v-shopify-pro-external-media-mapping', {
                template: '#v-shopify-pro-external-media-mapping-template',

                mounted() {
                    this.$nextTick(() => {
                        const mediaHeading = [...document.querySelectorAll('p')].find((element) => element.textContent.trim() === @json($mediaMappingTitle));

                        /** The two Pro cards travel together, under the page's own notice. */
                        mediaHeading?.closest('.bg-white')?.after(this.$el.closest('.shopify-pro-sections') ?? this.$el);
                    });
                },
            });
        </script>
    @endPushOnce
@endif
