@php
    $measurementTypes = (new \Webkul\Shopify\Services\Measurement\MeasurementTypeRegistry)->types();
@endphp

@pushOnce('scripts')
    <script type="module">
        /**
         * The connector's metaobject screens recognise only Shopify's three original
         * measurement types, so the types this package adds would otherwise render
         * without a unit, without bounds and without validation. The components are
         * extended here rather than replaced, and a missing seam is reported instead
         * of failing quietly.
         */
        const measurementUnits = @json($measurementTypes);

        const isMeasurement = (type) => Object.prototype.hasOwnProperty.call(measurementUnits, type ?? '');

        const pending = {};

        const apply = (definition, component, group, key, wrap) => {
            const target = definition?.[group];

            if (! target || typeof target[key] !== 'function') {
                console.warn(`[shopify-pro] measurement support could not extend ${component}.${group}.${key}`);

                return;
            }

            target[key] = wrap(target[key]);
        };

        /**
         * The connector registers these components from the page's own scripts,
         * which may run either side of this one, so a component that is not
         * registered yet is patched when it registers instead of being missed.
         */
        const extend = (component, group, key, wrap) => {
            const registered = app.component(component);

            if (registered) {
                apply(registered, component, group, key, wrap);

                return;
            }

            (pending[component] ??= []).push([group, key, wrap]);
        };

        const register = app.component.bind(app);

        app.component = function (name, definition) {
            const result = definition === undefined ? register(name) : register(name, definition);

            if (definition !== undefined && pending[name]) {
                pending[name].forEach(([group, key, wrap]) => apply(definition, name, group, key, wrap));

                delete pending[name];
            }

            return result;
        };

        const bound = (rules, key) => {
            const value = rules?.[key];

            return (value === undefined || value === null || value === '') ? null : value;
        };

        extend('v-metaobject-field-form', 'computed', 'hasMinMax', (original) => function () {
            return original.call(this) || isMeasurement(this.field.type);
        });

        extend('v-metaobject-field-form', 'computed', 'hasUnit', (original) => function () {
            return original.call(this) || isMeasurement(this.field.type);
        });

        extend('v-metaobject-field-form', 'computed', 'unitsFor', (original) => function () {
            const units = original.call(this);

            return units.length ? units : (measurementUnits[this.field.type] ?? []);
        });

        extend('v-metaobject-entries', 'methods', 'inputType', (original) => function (field) {
            const type = original.call(this, field);

            return (type === 'text' && isMeasurement(field.shopify_type)) ? 'number' : type;
        });

        extend('v-metaobject-entries', 'methods', 'numericBound', (original) => function (field, key) {
            return original.call(this, field, key) ?? (isMeasurement(field.shopify_type) ? bound(field.validations, key) : null);
        });

        extend('v-metaobject-entries', 'methods', 'stepFor', (original) => function (field) {
            return original.call(this, field) ?? (isMeasurement(field.shopify_type) ? 'any' : null);
        });

        extend('v-metaobject-entries', 'methods', 'passesFieldRules', (original) => function (field, list) {
            if (! isMeasurement(field.shopify_type)) {
                return original.call(this, field, list);
            }

            const rules = field.validations || {};
            const min = bound(rules, 'min');
            const max = bound(rules, 'max');

            for (const item of list) {
                const value = Number(item);

                if (item === '' || Number.isNaN(value)) {
                    return false;
                }

                if (min !== null && value < Number(min)) {
                    return false;
                }

                if (max !== null && value > Number(max)) {
                    return false;
                }
            }

            return true;
        });

        extend('v-metaobject-list-input', 'computed', 'stepValue', (original) => function () {
            return original.call(this) ?? (isMeasurement(this.field.shopify_type) ? 'any' : null);
        });

        extend('v-metaobject-list-input', 'computed', 'unitLabel', (original) => function () {
            const label = original.call(this);

            return (label === '' && isMeasurement(this.field.shopify_type)) ? (this.field.validations?.unit ?? '') : label;
        });

        extend('v-metaobject-list-input', 'methods', 'numericBound', (original) => function (key) {
            return original.call(this, key) ?? (isMeasurement(this.field.shopify_type) ? bound(this.field.validations, key) : null);
        });

        /**
         * The entry form posts without a rejection handler, so a rejected save
         * would otherwise leave the dialog open with nothing said. This reports
         * what the server rejected without touching the connector's component.
         */
        window.axios?.interceptors.response.use(null, (error) => {
            if (error?.response?.status === 422 && String(error?.config?.url ?? '').includes('metaobject-entries')) {
                Object.values(error.response.data?.errors ?? {}).flat().forEach((message) => {
                    window.app?.config?.globalProperties?.$emitter?.emit('add-flash', { type: 'warning', message });
                });
            }

            return Promise.reject(error);
        });
    </script>
@endPushOnce
