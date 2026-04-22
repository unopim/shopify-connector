# 1.4.0

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
