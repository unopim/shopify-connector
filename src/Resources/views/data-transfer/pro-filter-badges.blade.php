@php
    $proFeatures = resolve(\Webkul\Shopify\Support\ProFeatures::class);
@endphp

<v-shopify-pro-filter-badges
    :entities='@json($proFeatures->lockedExportFilterMap())'
    :titles='@json($proFeatures->exportFilterTitles())'
    entity-type="{{ $proFeatures->currentExportEntityType() }}"
    :badge='@json($proFeatures->badgeHtml())'
    :locked="@json(! $proFeatures->isInstalled())"
    :headings='@json($proFeatures->exportFilterHeadings())'
></v-shopify-pro-filter-badges>

@pushOnce('scripts')
    <script type="text/x-template" id="v-shopify-pro-filter-badges-template">
        <div v-if="offered">
            <x-shopify::pro-notice variant="page" />
        </div>

        <span v-else class="hidden"></span>
    </script>

    <script type="module">
        app.component('v-shopify-pro-filter-badges', {
            template: '#v-shopify-pro-filter-badges-template',

            props: {
                entities:   { type: Object, default: () => ({}) },
                titles:     { type: Object, default: () => ({}) },
                entityType: { type: String, default: '' },
                badge:      { type: String, default: '' },
                locked:     { type: Boolean, default: false },
                headings:   { type: Object, default: () => ({}) },
            },

            data() {
                return {
                    entity: this.entityType,
                };
            },

            mounted() {
                this.$emitter.on('entity-type-changed', this.changeEntity);

                this.observer = new MutationObserver(() => this.decorate());

                this.decorate();
            },

            beforeUnmount() {
                this.$emitter.off('entity-type-changed', this.changeEntity);

                this.observer?.disconnect();
            },

            computed: {
                fields() {
                    return new Set(this.entities[this.entity] ?? []);
                },

                /** The notice belongs to the screens that actually hold a locked filter. */
                offered() {
                    return this.locked && this.fields.size > 0;
                },

                /** Label text back to filter name, for labels that carry no id. */
                namesByTitle() {
                    return Object.fromEntries(
                        Object.entries(this.titles[this.entity] ?? {}).map(([name, title]) => [title, name])
                    );
                },
            },

            watch: {
                entity() {
                    this.$nextTick(this.decorate);
                },
            },

            methods: {
                /** Mirrors how the core filter fields resolve their entity. */
                changeEntity(value) {
                    if (value && typeof value === 'object') {
                        this.entity = value.id ?? '';

                        return;
                    }

                    try {
                        const parsed = JSON.parse(value);

                        this.entity = (parsed && typeof parsed === 'object' && parsed.id) ? parsed.id : value;
                    } catch (exception) {
                        this.entity = value;
                    }
                },

                /** The badge is the rendered core component, not a copy of it. */
                build(markup) {
                    const holder = document.createElement('div');

                    holder.innerHTML = markup;

                    return holder.firstElementChild;
                },

                /** The label's own text, without the chips core adds inside it. */
                labelText(label) {
                    const clone = label.cloneNode(true);

                    clone.querySelectorAll('.unsaved-badge, .shopify-pro-badge, .icon-language').forEach((chip) => chip.remove());

                    return clone.textContent.replace(/\s+/g, ' ').trim();
                },

                nameFor(label) {
                    const id = label.getAttribute('for');

                    if (id && this.fields.has(id)) {
                        return id;
                    }

                    return this.namesByTitle[this.labelText(label)] ?? null;
                },

                /**
                 * Without the package that reads them, a Pro filter is shown but not
                 * offered. Nothing is disabled and no value is dropped, because a
                 * profile saved with Pro would lose the filter it carries. The lock
                 * lives on the element itself so a Vue re-render cannot wipe it, and
                 * the group swallows the pointer, so a click on a locked filter never
                 * reaches the core unsaved tracker as a change.
                 */
                hold(container) {
                    if (! this.locked || ! container) {
                        return;
                    }

                    container.dataset.shopifyProLocked = '';
                    container.dataset.unsavedIgnore = '';
                    container.style.pointerEvents = 'none';

                    container.querySelectorAll('input, select, textarea, button').forEach((control) => {
                        control.tabIndex = -1;
                    });

                    /**
                     * Buttons carry no value, so they can be disabled outright: a
                     * locked builder then says in the control itself that nothing
                     * can be added, not only in the badge above it.
                     */
                    container.querySelectorAll('button:not([disabled])').forEach((button) => {
                        button.disabled = true;
                        button.dataset.shopifyProDisabled = '';
                    });
                },

                /** Core exports share these controls, so a lock has to be reversible. */
                release(container) {
                    if (! container || container.dataset.shopifyProLocked === undefined) {
                        return;
                    }

                    delete container.dataset.shopifyProLocked;
                    delete container.dataset.unsavedIgnore;

                    container.style.removeProperty('pointer-events');

                    container.querySelectorAll('input, select, textarea, button').forEach((control) => {
                        control.removeAttribute('tabindex');
                    });

                    container.querySelectorAll('button[data-shopify-pro-disabled]').forEach((button) => {
                        button.disabled = false;

                        delete button.dataset.shopifyProDisabled;
                    });
                },

                groupOf(label) {
                    return label.closest('[data-control-group]') ?? label.parentElement;
                },

                /** Trees and condition builders fill in later, so each pass decorates again. */
                mark(holder, name, container) {
                    if (! this.fields.has(name)) {
                        if (holder.dataset.shopifyProFilter !== undefined) {
                            holder.querySelector('.shopify-pro-badge')?.remove();

                            delete holder.dataset.shopifyProFilter;
                        }

                        this.release(container);

                        return;
                    }

                    if (holder.dataset.shopifyProFilter === undefined) {
                        holder.append(this.build(this.badge));

                        holder.dataset.shopifyProFilter = name;
                    }

                    this.hold(container);
                },

                decorate() {
                    if (! this.badge) {
                        return;
                    }

                    this.observer.disconnect();

                    document.querySelectorAll('label').forEach((label) => {
                        const name = label.dataset.shopifyProFilter ?? this.nameFor(label);

                        if (name) {
                            this.mark(label, name, this.groupOf(label));
                        }
                    });

                    /** A filter core titles with a card heading carries no label to match on. */
                    document.querySelectorAll('p').forEach((heading) => {
                        const name = heading.dataset.shopifyProFilter ?? this.headings[this.labelText(heading)];

                        if (name) {
                            this.mark(heading, name, heading.parentElement);
                        }
                    });

                    this.observer.observe(document.body, { childList: true, subtree: true });
                },
            },
        });
    </script>
@endPushOnce
