# Catalog Network

Owns HomePass serviceability, addresses, technology regions, network topology, contractor coverage and network reference catalogs.

## Use

Network catalog APIs are composed in `../Catalog/routes/api.php`. Import or maintain HomePass records, attach network paths, and query eligibility before order capture. Use `sophix:catalog-network:ops-status` for catalog quality checks.

## Configure

Node types, house types, HomePass statuses, technologies and coverage mappings are catalog data. Geo enrichment is an adapter selected by deployment configuration.

## Extend

Add country-specific address attributes as optional structured metadata or catalogs. Implement `GeoAdapter` for a real GIS/geocoder, preserving the generic HomePass and topology contracts.

## Test

Tests live in `tests/Feature`; serviceability integration scenarios may also be exercised from fulfillment tests.
