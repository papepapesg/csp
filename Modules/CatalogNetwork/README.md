# Catalog Network

Owns HomePass serviceability, addresses, technology regions, network topology, contractor coverage and network reference catalogs.

## Use

Network catalog APIs are composed in `../Catalog/routes/api.php`. Import or maintain HomePass records, attach network paths, and query eligibility before order capture. Use `sophix:catalog-network:ops-status` for catalog quality checks.

## Configure

Node types, house types, HomePass statuses, technologies and coverage mappings are catalog data. Geo enrichment is an adapter selected by deployment configuration.

## Extend

Add country-specific address attributes as optional structured metadata or catalogs. Implement `GeoAdapter` for a real GIS/geocoder, preserving the generic HomePass and topology contracts.

## Exposed APIs

- `GET homepass`
- `GET homepass/eligible`
- `GET homepass/{homepass}`
- `GET homepass/{homepass}/eligible-contractors`
- `GET house-types`
- `GET network-nodes`
- `GET tech-regions`
- `GET tech-regions/{techRegion}`
- `PATCH homepass/{homepass}/address`
- `PATCH homepass/{homepass}/network-path`
- `PATCH homepass/{homepass}/status`
- `PATCH tech-regions/{techRegion}`
- `POST homepass`
- `POST homepass/bulk-import`
- `POST homepass/{homepass}/enrich-from-geo`
- `POST house-types`
- `POST network-nodes`
- `POST network-nodes/{networkNode}/retire`
- `POST tech-regions`
- `POST tech-regions/{techRegion}/activate`
- `POST tech-regions/{techRegion}/contractors`
- `POST tech-regions/{techRegion}/retire`

## Data models

- `HomePass`
- `HomePassStatusCode`
- `HomePassTechRegion`
- `HouseType`
- `NetworkNode`
- `TechContractorSkill`
- `TechRegion`
- `TechRegionContractor`

## Services

- `GeoAdapter`
- `GeoClient`
- `GoogleMapsGeoAdapter`
- `HomePassTopologyService`
- `NetworkCatalogService`
- `NoOpGeoAdapter`
- `TechCoverageService`

## Events

- `CatalogEvents::HOMEPASS_STATUS_CHANGED`
- `CatalogEvents::TOPIC`

## Commands

- `sophix:catalog-network:ops-status`

## Test

Tests live in `tests/Feature`; serviceability integration scenarios may also be exercised from fulfillment tests.
