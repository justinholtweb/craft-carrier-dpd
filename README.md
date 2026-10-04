# Carrier for DPD

DPD Croatia and DPD Slovenia through **DPD EasyShip** for [Carrier](https://justinholt.com/plugins/craft-carrier), the shipping-carrier gateway for Craft Commerce 5.

> **Which EasyShip?** This is DPD's own portal and web service at **easyship.hr** and **easyship.si**. It is not Easyship.com, the unrelated multi-carrier aggregator. If you sign in at `easyship.hr` or `easyship.si`, this is the right package; if you sign in at `app.easyship.com`, it is not.

This package translates the EasyShip API and nothing else. Packing, checkout, label storage, bulk printing, tracking schedules, the pickup-point picker and the log all live in Carrier.

## What it does

| | |
|---|---|
| Labels | `parcel/import`, then `parcel/print` straight away, in the same purchase (see below) |
| Label formats | PDF A4, PDF A6, ZPL |
| Services | DPD Home (B2C), DPD Classic (B2B), DPD Pickup parcel shop or locker. With COD, each becomes its COD parcel type |
| Cash on delivery | `cod_amount` in euros, `cod_purpose` (≤ 14 characters), and a setting for how multi-parcel COD is split |
| Void | Deletes the parcel if it is unprinted, otherwise cancels it. Cancelled parcels drop off the manifest |
| Reprint | `parcel/print` in batches, but only in the first two minutes or so (see below) |
| Tracking | The latest status of each parcel. EasyShip offers no history and no timestamps |
| Collections | `pickupdiff/import`, with at least 30 minutes' notice |
| End of day | Today's manifest PDF, plus the COD manifest when any shipment carries cash |

EasyShip has no rates. Price DPD methods with table rates. DPD documents **no test system**: its own advice is to test in production and delete the parcel while it is unprinted.

## Labels vanish after two minutes

About two minutes after a parcel is first printed, EasyShip sends its data to DPD, and from then on every print of it returns an **empty PDF**. This package fetches the label in the same request that creates the parcel, and Carrier stores those bytes, so your labels are always available from Carrier. A later reprint that comes back empty is reported as an error explaining this, and Carrier keeps the original. If EasyShip creates a parcel but will not print it, the parcel is deleted (still possible, since it was never printed) and the purchase can simply be retried.

## Setting up

1. Install Carrier, then this package: `composer require justinholtweb/craft-carrier-dpd` and `php craft plugin/install carrier-dpd`.
2. In **Carrier → Connections**, add a DPD EasyShip connection:
   - **EasyShip country**: Croatia or Slovenia. The accounts are separate.
   - **Username and password**: your EasyShip portal login. DPD issues no separate API key. Store the password in an environment variable.
   - **Print the ship-from address on labels**: leave this off to print the sender saved in your EasyShip profile.
3. Press **Test connection**.

Addresses need a **house number separate from the street**. Carrier splits "Ilica 242a" automatically and recognises Croatian "bb" (no number). An address with no number at all is refused before anything is sent. Home and parcel-shop delivery need the recipient's phone number or email address, so make sure your checkout collects a phone number (Commerce addresses have no phone field; map one in Carrier's settings).

## Parcel shops

**EasyShip has no list of parcel shops.** DPD's manual tells you to ask your local DPD office for the PUDO ids and locations, and DPD's public shop finder cannot be read by a server. So the list comes from you:

1. Ask DPD Croatia or DPD Slovenia for the parcel-shop list.
2. Save it as a CSV with these columns (header row required; `id`, `name` and `country` are mandatory):

   ```
   id,name,type,street,city,postcode,country,latitude,longitude,hours,cod
   HR00006,DPD Pickup Tisak Ilica,shop,Ilica 120,Zagreb,10000,HR,45.8131,15.9601,Mon–Fri 07:00–20:00,1
   ```

   - `id`: the PUDO id, `HR` or `SI` followed by five digits (at most 7 characters)
   - `type`: `shop` or `locker` (default `shop`)
   - `latitude` / `longitude`: decimal degrees, for distance sorting in the picker
   - `cod`: `0` or `no` if the point does not take cash on delivery (default yes)
   - A semicolon-separated file works too.
3. Import it in **Carrier → Pickup points → Import** for your DPD connection.

DPD connections use Carrier's manual point mode: nothing is ever synced, so imported points are only changed by you. Re-import whenever DPD sends you an updated list. You can also type a PUDO id on the shipment screen. Either way, parcel-shop delivery needs a point id: EasyShip refuses `D-B2C-PSD` parcels without one, and also wants the parcel's weight, the recipient's mobile number and the parcel's dimensions. Carrier checks for all of these before sending.

## Unverified

This package was written against DPD's published EasyShip manual (v2.2, August 2025) without a live account. These points are marked **UNVERIFIED** in the code and should be checked on a first live shipment:

- What an "empty PDF" from a late reprint looks like byte for byte. It is detected as no bytes, something that is not a PDF, or a PDF with no pages.
- The HTTP status of a validation error (believed to be 200 with `"status":"err"`; both are handled).
- What the COD split options (`firstonly`, `avg`, `all`) do on multi-parcel shipments. The default is `firstonly`.
- How dimensions apply to a multi-parcel parcel-shop shipment. The largest parcel's are sent.
- Whether `cancel` works on a parcel that has been printed but not yet sent to DPD.
- Whether a customer collecting from a parcel shop shows as `DELIVERED`.
- Whether DPD offers test accounts on request.

## Requirements

Craft CMS 5.3+, Craft Commerce 5, Carrier 5, PHP 8.2+.

## Licence

The Craft License. See [LICENSE.md](LICENSE.md). Carrier for DPD is free: no editions, no licence key, and no licensing code in the plugin. It needs [Carrier](https://justinholt.com/plugins/craft-carrier), which is commercial.
