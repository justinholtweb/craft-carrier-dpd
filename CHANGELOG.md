# Release Notes for Carrier for DPD

## 5.0.0 - 2026-10-03

- Initial release.
- DPD Croatia and DPD Slovenia through DPD EasyShip (easyship.hr / easyship.si).
- DPD Home, DPD Classic and DPD Pickup parcel-shop services, each with cash on delivery.
- Labels as PDF A4, PDF A6 or ZPL, fetched in the same purchase that creates the parcel, because EasyShip serves only empty PDFs about two minutes after the first print.
- Multi-parcel shipments, with per-parcel weights.
- Voids that delete unprinted parcels and cancel printed ones.
- Reprints that report EasyShip's late empty PDFs as an error instead of storing them over the original label.
- Latest-status tracking mapped across every EasyShip status.
- Courier collections (`pickupdiff/import`) and the end-of-day manifest, plus the COD manifest when it applies.
- Parcel shops from a merchant-imported CSV of DPD's PUDO ids, since EasyShip has no point list.
