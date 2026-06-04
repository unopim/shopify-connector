# 2.0.0 ( 4 June 2026 )

## Features
- UnoPIM–Shopify connector app — install from Shopify, enter your UnoPIM integration details, and click **Connect**. No manual credential setup needed in UnoPIM.
- SaaS authentication flow with auto-login and proxy-based Shopify API access (`SaasProxyClient`, `SaasAutoLoginController`, `SaasCredentialController`).
- Automatic credential revocation when the linked API key is deleted.
- **Bulk product export** built on a phase-based architecture (Core, Media, Translation, Publishing phases) using Shopify Bulk Operations for large catalogs.
- **Bulk product import** using Shopify Bulk Operations with batched mapping and caching for high-volume catalogs.
- Variant SKU filter support in product export.
- New `ShopifyPollBulkOperations` command and polling job to track bulk operation status.

## Changed
- Restructured product export into a modular, phase-based pipeline with per-phase progress tracking and deferred job-track completion.
- Widened the Shopify credentials `access_token` column to support longer tokens.
- Updated Vite to `^6.4.2` and laravel-vite-plugin to `^1.2.0`.
- Enhanced credential UI tooltips and field handling for SaaS credentials.

## Bug fixes
- Skip variants missing super-attribute values to prevent import crashes on Configurable product updates.
- Added a 3-retry guard when downloading bulk-operation results.
- Handle product recreation when a product was deleted from Shopify.
- Fixed collection assignment and updates during bulk export.
- Fixed inventory quantity sync and simple product export in bulk mode.
- Fixed incorrect product count in job status and synced the UI job tracker with running phase jobs.
- Show a UI error when no credential is configured.

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
