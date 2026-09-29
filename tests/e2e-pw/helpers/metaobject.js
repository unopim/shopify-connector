/**
 * The fields a metaobject definition carries, read from the attribute the page
 * hands its entries component. Reading them from the page keeps the entry
 * assertions tied to the definition actually seeded, instead of a fixed count
 * that drifts with the fixture.
 */
export async function definitionFields(page, url) {
    const response = await page.request.get(url);

    const match = (await response.text()).match(/<v-metaobject-entries[\s\S]*?:fields='([\s\S]*?)'/);

    if (! match) {
        throw new Error(`No metaobject entry fields were found on ${url}.`);
    }

    return JSON.parse(match[1]);
}

/**
 * A measurement field is the one Shopify stores with a unit, so the unit its
 * validations carry is what marks it, rather than a copy of the type catalogue.
 */
export function measurementFields(fields) {
    return fields.filter((field) => Boolean(field.validations?.unit));
}
