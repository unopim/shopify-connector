# 3.0.0 ( 06 August 2026 )

## Features
- Metaobject support — new `shopify_metaobject` attribute type; create and manage Shopify metaobject definitions (with a fields datagrid) and entries from UnoPim, including metaobject options: active/draft status, translations, publish entries as web pages, Storefront API access, and Customer Account API access. Exported to Shopify and imported back.
- Metaobject reference metafield — assign metaobject entries to products; referenced entries are created and updated automatically on export.
- Shopify taxonomy category support — new `shopify_taxonomy` attribute type with a searchable category picker; the category is exported with the product and imported back.
- Category-constrained metafield definitions — a metafield definition can be limited to selected taxonomy categories, and only matching products send that metafield.
- Dynamic product association mapping — metafield product references map to any active UnoPim association type, and newly added association types appear automatically.
- File reference metafields can now restrict accepted file types (any or media only) and support mixed-media galleries (image, video and file).
- Collection field mapping — a dedicated screen to map UnoPim category attributes to Shopify collection fields (title, description, SEO title, SEO description, handle and collection type), used for both category export and import.

## Changed
- Compatibility with UnoPim v3.0.0.
- Inventory is always multi-location now; the old single-location handling has been removed.

## Bug fixes
- Fixed the image metafield not being exported for the parent of a configurable product.
- Fixed inventory being reset to zero on product update.
- Fixed metafield definition export failing on the newer Shopify API versions.

# 2.0.0 ( 20 May 2026 )

## Features
- UnoPIM-Shopify connector app — install from Shopify, enter your UnoPIM integration details, and click Connect.
- No manual credential setup needed in UnoPIM.

# 1.5.0 ( 30 April 2026 )

## Features
- Bulk product export.
- Bulk product import.

# 1.4.0 ( 15 April 2026 )

## Changed
- Compatibility with UnoPIM v2.0.0.

# 1.3.0

## Features
- Automatic Access token generation from at the time of Credential add which requires Shopify Shop URL, Client ID and Client Secret.
- Compatibility with latest Shopify Admin API version 2026-01.

## Bug fixes
- Added validation error for incorrect Shop URL format without requiring the Save button click.
- Fixed category description export.
- Fixed currency fetching based on the mapped channel in the export job.
- Fixed multi-locale category content import.
- Fixed category update during product export.
- Fixed product import duplicacy by validating unique product number and SKU.

# 1.2.0 ( 28 July 2025 )

- Added Playwright end-to-end testing.
- Removed auth.json and updated the GitHub workflow.
- Introduced metafield definition export and asset export functionality.
- Asset export now supports .mp4 files for video export to Shopify.
- Fixed permission issue (#13).
- Improved media import: If a product has more than 20 images, only the first 10 will be imported.

# 1.1.0
- Import **Categories** from Shopify to UnoPim.
- Import **Products** from Shopify to UnoPim

# 1.0.0 - "Here We Go" (14-10-2024)

## Features
- Export **Categories** from UnoPim as **Collections** in Shopify.
- Export **Products** from UnoPim to Shopify, including:
  - **Product Images**
  - **Inventory quantity**, **SEO data**, and comprehensive product data.
- Filter exportable data based on **SKU** (Identifier) and **Currency**.
- Export **Metafields** for:
  - **JSON**
  - **Single-line text**
  - **Integer** types.
- Define and standardize fixed values for export.
- Sync multiple Shopify store
