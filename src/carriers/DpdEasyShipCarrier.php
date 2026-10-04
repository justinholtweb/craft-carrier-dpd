<?php

namespace justinholtweb\carrierdpd\carriers;

use Craft;
use DateTime;
use DateTimeZone;
use justinholtweb\carrier\auth\BasicAuth;
use justinholtweb\carrier\base\ActionResult;
use justinholtweb\carrier\base\AuthInterface;
use justinholtweb\carrier\base\Capabilities;
use justinholtweb\carrier\base\Carrier;
use justinholtweb\carrier\base\Feature;
use justinholtweb\carrier\base\Field;
use justinholtweb\carrier\base\HealthResult;
use justinholtweb\carrier\base\LabelResult;
use justinholtweb\carrier\base\PointPage;
use justinholtweb\carrier\base\Response;
use justinholtweb\carrier\base\ShipmentResult;
use justinholtweb\carrier\base\TrackingStatus;
use justinholtweb\carrier\base\Transport;
use justinholtweb\carrier\helpers\Addresses;
use justinholtweb\carrier\helpers\LabelFormat;
use justinholtweb\carrier\helpers\Units;
use justinholtweb\carrier\models\canonical\Address;
use justinholtweb\carrier\models\canonical\Label;
use justinholtweb\carrier\models\canonical\PickupPoint;
use justinholtweb\carrier\models\canonical\PickupRequest;
use justinholtweb\carrier\models\canonical\PointQuery;
use justinholtweb\carrier\models\canonical\ShipmentRef;
use justinholtweb\carrier\models\canonical\ShipmentRequest;
use justinholtweb\carrier\models\canonical\TrackingEvent;
use justinholtweb\carrier\models\canonical\TrackingInfo;

/**
 * DPD Croatia and DPD Slovenia through the EasyShip web service (easyship.hr / easyship.si).
 *
 * "EasyShip" here is **DPD's own portal and API**, not Easyship.com, the unrelated multi-carrier
 * aggregator. A merchant who logs in at easyship.hr or easyship.si wants this carrier; one who logs
 * in at app.easyship.com does not. The manual covers Croatia and Slovenia only.
 *
 * Written against *DPD EasyShip Webservice User Manual for DPD Slovenia and DPD Croatia* v2.2
 * (2025-08-05), DataLinx/DPD-PHP-SDK, and unauthenticated probes of both hosts. No live account,
 * and **DPD documents no sandbox**: the manual's advice is to test in production and delete the
 * parcel while it is still unprinted.
 *
 * ## How it maps
 *
 * - `parcel/import` creates the parcels and returns `pl_number[]`, one 14-digit number per parcel,
 *   which is also the tracking number. COD and parcel-shop delivery are not options but **parcel
 *   types**: service × COD picks one code (`D-B2C`, `D-COD-B2C`, `D-B2C-PSD`, `D-B2C-COD-PSD`, `D`,
 *   `D-COD`).
 * - `parcel/print` is called **immediately**, in the same `createShipment()`, and the bytes are
 *   returned with the shipment. See the label trap below for why that is not optional.
 * - Void tries `parcel/delete` (unprinted parcels only) and falls back to `parcel/cancel` (after
 *   printing). Because labels are always printed at creation, a void is in practice a cancel:
 *   the parcel stays in EasyShip as cancelled and drops off the manifest.
 * - Tracking is `parcel/status`, one parcel per call, **latest status only**: no history and no
 *   timestamp. Each distinct status becomes one undated event.
 * - Collections are `pickupdiff/import` ("carorder"). End of day is `parcel/manifest`, a PDF of
 *   the parcels DPD has received data for; DPD has no "close" call, because EasyShip transmits
 *   parcel data on its own.
 *
 * ## Vendor traps
 *
 * - **Labels vanish.** About two minutes after a parcel is first printed, EasyShip transmits its
 *   data to DPD, the status becomes `sent`, and **every later print returns an empty PDF**. So:
 *   the label is fetched inside `createShipment()` and returned there, and the engine stores those
 *   bytes. A reprint that comes back empty or unreadable is reported as a failure with that
 *   explanation, never passed off as a label; the engine keeps the stored original.
 * - If printing fails straight after creation, the parcel is still unprinted and so still
 *   deletable. It is deleted, and the purchase reported as retryable. Only if that delete fails too
 *   is it reported as uncertain, with the parcel number.
 * - **Every parameter goes in the query string, on POST too**, with an empty body. Per-parcel
 *   weights repeat the key (`&weight=4.5&weight=6.5`), which `http_build_query()` cannot produce
 *   (it writes `weight[0]=`), so the query is built by hand with `rawurlencode()`. A literal `+`
 *   in an email must arrive as `%2B`, which RFC 3986 encoding guarantees.
 * - **Basic auth** with the portal login. The older `?username=&password=` query style used by
 *   DataLinx's SDK answered a bad login with an empty 401, so it is not used. A 401 body is the
 *   plain string `Invalid username or password`, labelled `application/json`.
 * - **Use the documented verb.** `parcel/status` answers 405 to POST; `parcel/import` answers 403
 *   to GET.
 * - Validation errors arrive as `{"status":"err","errlog":"… (ES-356)"}`, probably on HTTP 200, so
 *   every answer is read by `status`, not by HTTP code.
 * - `street` must not contain the house number, which goes in `rPropNum` (≤ 8 chars, case kept:
 *   "4A"). Croatian "bb" (bez broja, no number) is recognised. With no number at all, the request
 *   is refused before it is sent rather than guessed.
 * - B2C and parcel-shop types need `predict=1` and a phone or email (ES-395/396); parcel-shop types
 *   also need weight, phone and the 9-digit `dimension` `LLLWWWHHH` in cm (ES-411–415). Checked
 *   before sending.
 * - Field lengths are short (35, 30 for the sender) and are truncated with `mb_substr()`.
 *   Diacritics are fine.
 * - The delete/cancel answer is free text (`"1 deleted"`, `"No matched parcels"`): the leading
 *   integer is the count.
 * - Collection `date_from` must be at least 30 minutes in the future (ES-130); a start time that is
 *   too soon is moved to 31 minutes from now.
 * - The manifest lists only parcels in status `sent`. Asked for straight after labelling, it can
 *   be empty.
 *
 * ## Parcel shops
 *
 * EasyShip has **no point-list endpoint**. The manual says to ask local DPD for the PUDO ids, and
 * the public locator sits behind a Cloudflare challenge. `pickupPoints()` therefore returns an
 * empty page without a request: the points come from a CSV the merchant imports (Carrier → Pickup
 * points → Import), which a sync never touches. Parcel-shop delivery still needs
 * `$request->pointId` (`pudo_id`, `HR` + 5 digits, ≤ 7 chars) from that list, or typed on the
 * shipment screen.
 *
 * ## UNVERIFIED
 *
 * - What an "empty PDF" looks like byte for byte. A reprint is treated as empty when it has no
 *   bytes, is not a PDF, declares `/Count 0`, or contains no page object (unless it uses object
 *   streams, where pages cannot be seen without decompressing; those are trusted).
 * - The HTTP status of a validation error (believed 200).
 * - `parcel_cod_type` semantics (`avg`, `all`, `firstonly`) on multi-parcel shipments. The
 *   default sent is the merchant's setting, `firstonly` unless changed.
 * - Whether a COD amount on a non-COD parcel type is ignored or rejected. It is never sent.
 * - `dimension` for a multi-parcel shipment: one value per shipment, taken from the largest parcel.
 * - Whether `cancel` works on a parcel that is printed but not yet `sent`.
 * - Whether collection from a parcel shop reports `DELIVERED` (assumed).
 * - Whether a test account is available on request.
 */
class DpdEasyShipCarrier extends Carrier
{
    public const COUNTRIES = ['hr' => 'Croatia', 'si' => 'Slovenia'];

    /** Every EasyShip status, case-insensitively, onto the normalised vocabulary. */
    private const STATUS_MAP = [
        'CREATED' => TrackingStatus::PRE_TRANSIT,
        'NOT_YET_PRINTED' => TrackingStatus::PRE_TRANSIT,
        'PRINTED' => TrackingStatus::PRE_TRANSIT,
        'PREPARED_TO_SEND' => TrackingStatus::PRE_TRANSIT,
        'SENT' => TrackingStatus::PRE_TRANSIT,
        'DRIVERS_PICK_UP' => TrackingStatus::IN_TRANSIT,
        'PICK_UP' => TrackingStatus::IN_TRANSIT,
        'HUB_SCAN' => TrackingStatus::IN_TRANSIT,
        'Inbound' => TrackingStatus::IN_TRANSIT,
        'LOADING' => TrackingStatus::IN_TRANSIT,
        'WAREHOUSE' => TrackingStatus::IN_TRANSIT,
        'CONSOLIDATION' => TrackingStatus::IN_TRANSIT,
        'CUSTOMS_CLEARANCE_PROCESS' => TrackingStatus::IN_TRANSIT,
        'EXPORT_IMPORT_CLEARED' => TrackingStatus::IN_TRANSIT,
        'OUT_FOR_DELIVERY' => TrackingStatus::OUT_FOR_DELIVERY,
        'DELIVERED' => TrackingStatus::DELIVERED,
        'DELIVERED_BY_DRIVER_TO_DPD_PARCELSHOP' => TrackingStatus::READY_FOR_PICKUP,
        'DELIVERY_ATTEMPT_NOT_SUCCESSFUL' => TrackingStatus::EXCEPTION,
        'Inbound exception' => TrackingStatus::EXCEPTION,
        'DRIVERS_RETURN' => TrackingStatus::EXCEPTION,
        'Return to sender' => TrackingStatus::RETURNED,
        'SYSTEM_RETURN' => TrackingStatus::RETURNED,
        'CANCELLED' => TrackingStatus::CANCELLED,
        'CANCELLED_SENT' => TrackingStatus::CANCELLED,
        'PREPARED_TO_CANCEL' => TrackingStatus::CANCELLED,
        'DELETED' => TrackingStatus::CANCELLED,
    ];

    /** Statuses that mean "no news": the parcel keeps whatever status it had. */
    private const NO_CHANGE = ['unknown', 'additional_information'];

    /** `parcels` is limited to 500 characters: about 33 fourteen-digit numbers. */
    private const PARCELS_PARAM_MAX = 500;

    private const ZONE = 'Europe/Zagreb';

    public static function handle(): string
    {
        return 'dpd-easyship';
    }

    public static function displayName(): string
    {
        return 'DPD EasyShip';
    }

    public static function region(): string
    {
        return Craft::t('carrier', 'Europe');
    }

    public static function description(): string
    {
        return Craft::t('carrier', 'DPD Croatia and DPD Slovenia through their EasyShip portal (easyship.hr / easyship.si, not Easyship.com): labels, cash on delivery, parcel shops, tracking, collections and the daily manifest.');
    }

    public static function setupUrl(): ?string
    {
        return 'https://easyship.hr/';
    }

    public static function capabilities(): Capabilities
    {
        return Capabilities::make()
            ->supports(
                Feature::LABELS, Feature::CANCEL, Feature::REPRINT, Feature::TRACKING,
                Feature::PICKUPS, Feature::CLOSE_DAY, Feature::COD, Feature::INTERNATIONAL,
                Feature::MULTI_PIECE,
            )
            // No point API: the list is the merchant's imported CSV. See the class docblock.
            ->points(Capabilities::POINTS_MANUAL, PickupPoint::TYPE_SHOP, PickupPoint::TYPE_LOCKER)
            ->labelFormats(LabelFormat::PDF_A4, LabelFormat::PDF_A6, LabelFormat::ZPL)
            ->originCountries('HR', 'SI')
            ->trackingBatch(1);
    }

    public static function settingsFields(): array
    {
        return [
            Field::select('country', 'EasyShip country', [
                'hr' => 'Croatia (easyship.hr)',
                'si' => 'Slovenia (easyship.si)',
            ], [
                'required' => true,
                'instructions' => 'Where your DPD contract is. EasyShip is DPD’s own portal, not Easyship.com.',
            ]),
            Field::text('username', 'EasyShip username', [
                'required' => true,
                'instructions' => 'The login you use at easyship.hr or easyship.si. DPD issues no separate API key, and has no test system.',
            ]),
            Field::secret('password', 'EasyShip password', [
                'required' => true,
            ]),
            Field::select('codSplit', 'Cash on delivery across several parcels', [
                'firstonly' => 'First parcel only (firstonly)',
                'avg' => 'Averaged across the parcels (avg)',
                'all' => 'All parcels (all)',
            ], [
                'instructions' => 'EasyShip’s parcel_cod_type. Only matters when one COD shipment has more than one parcel.',
            ]),
            Field::boolean('sendSender', 'Print the ship-from address on labels', [
                'instructions' => 'Off: labels show the sender saved in your EasyShip profile.',
            ]),
        ];
    }

    public static function services(): array
    {
        return [
            'home' => ['name' => 'DPD Home (B2C)', 'international' => true, 'points' => false],
            'classic' => ['name' => 'DPD Classic (B2B)', 'international' => true, 'points' => false],
            'pickup' => ['name' => 'DPD Pickup parcel shop / locker', 'international' => false, 'points' => true],
        ];
    }

    /** `parcel_type` by service and whether there is cash to collect. */
    private const PARCEL_TYPES = [
        'home' => ['D-B2C', 'D-COD-B2C'],
        'classic' => ['D', 'D-COD'],
        'pickup' => ['D-B2C-PSD', 'D-B2C-COD-PSD'],
    ];

    public static function trackingUrl(string $trackingNumber): ?string
    {
        return 'https://www.dpdgroup.com/hr/mydpd/my-parcels/track?parcelNumber=' . rawurlencode($trackingNumber);
    }

    protected function buildAuth(): ?AuthInterface
    {
        return new BasicAuth('username', 'password');
    }

    protected function buildTransport(): Transport
    {
        return (new Transport())
            ->setBaseUri('https://easyship.' . $this->country() . '/api')
            ->setTimeout(60);
    }

    /**
     * `parcel/status` for a number that cannot exist: a 401 proves the login wrong, and any other
     * answer from the API proves it right.
     */
    protected function probe(): HealthResult
    {
        $response = $this->transport()->request('GET', 'parcel/status?' . self::query([['parcel_number', '00000000000000']]));

        if ($response->unauthorized()) {
            return HealthResult::fail(
                Craft::t('carrier', 'EasyShip refused the username or password.'),
                [
                    Craft::t('carrier', 'Use the login you sign in to {host} with.', ['host' => 'easyship.' . $this->country()]),
                    Craft::t('carrier', 'Croatian and Slovenian EasyShip accounts are separate. Check the country above.'),
                ],
            );
        }

        if ($response->status === 0 || $response->status >= 500) {
            return HealthResult::fail(Craft::t('carrier', 'EasyShip did not answer: {error}', ['error' => $response->errorMessage()]));
        }

        return HealthResult::pass(
            Craft::t('carrier', 'Connected to DPD EasyShip {country} as {user}.', [
                'country' => self::COUNTRIES[$this->country()],
                'user' => (string)$this->setting('username'),
            ]),
            array_filter([Craft::t('carrier', 'Backend version') => (string)$response->header('easyship-backend-version')]),
        );
    }

    // =========================================================================
    // Labels
    // =========================================================================

    public function createShipment(ShipmentRequest $request): ShipmentResult
    {
        $to = $request->shipTo;
        $from = $request->shipFrom;

        if (!$to || !$from) {
            return ShipmentResult::rejected(Craft::t('carrier', 'DPD needs both a ship-from and a ship-to address.'));
        }

        $count = count($request->parcels);

        if ($count < 1 || $count > 99) {
            return ShipmentResult::rejected(Craft::t('carrier', 'A DPD shipment holds between 1 and 99 parcels.'));
        }

        $service = isset(self::PARCEL_TYPES[$request->serviceCode]) ? $request->serviceCode : ($request->pointId !== '' ? 'pickup' : 'home');
        $type = self::PARCEL_TYPES[$service][$request->hasCod() ? 1 : 0];
        $b2c = $service !== 'classic';
        $psd = $service === 'pickup';
        [$street, $number] = self::streetAndNumber($to);
        $phone = ltrim(Addresses::normalisePhone($to->phone), '+');
        $dimension = self::dimension($request);

        $problem = match (true) {
            $number === '' => Craft::t('carrier', 'DPD needs a house number, separately from the street. Add one to the address, or “bb” if it has none.'),
            $psd && $request->pointId === '' => Craft::t('carrier', 'Choose a DPD parcel shop for this shipment.'),
            $psd && $phone === '' => Craft::t('carrier', 'DPD needs the recipient’s mobile number to deliver to a parcel shop.'),
            $psd && $dimension === null => Craft::t('carrier', 'DPD needs the parcel’s dimensions to deliver to a parcel shop.'),
            $b2c && $phone === '' && $to->email === '' => Craft::t('carrier', 'DPD needs the recipient’s phone number or email address for home and parcel-shop delivery.'),
            $request->hasCod() && in_array(strtoupper($to->countryCode), ['HR', 'SI'], true) && !in_array(strtoupper($request->codCurrency ?: $request->currency), ['EUR', ''], true)
                => Craft::t('carrier', 'DPD collects cash on delivery in euros in Croatia and Slovenia.'),
            default => null,
        };

        if ($problem !== null) {
            return ShipmentResult::rejected($problem);
        }

        $params = [];
        $add = static function(string $key, mixed $value, int $max = 0) use (&$params): void {
            $value = trim((string)$value);

            if ($value !== '') {
                $params[] = [$key, $max > 0 ? mb_substr($value, 0, $max) : $value];
            }
        };

        $add('name1', $to->company !== '' ? $to->company : $to->name, 35);
        $add('contact', $to->company !== '' ? $to->name : '', 35);
        $add('street', $street, 35);
        $add('rPropNum', $number, 8);
        $add('city', $to->city, 35);
        $add('country', strtoupper($to->countryCode), 2);
        $add('pcode', $to->postcode, 9);
        $add('email', $to->email, 50);
        $add('phone', $phone, 30);
        $add('sender_remark', $request->instructions, 50);
        $add('num_of_parcel', $count);

        foreach ($request->parcels as $parcel) {
            $add('weight', self::kg($parcel->weightG));
        }

        $add('order_number', $request->reference, 20);
        $add('order_number2', $request->reference2, 20);
        $add('parcel_type', $type);

        if ($request->hasCod()) {
            $add('cod_amount', number_format($request->codAmount, 2, '.', ''));
            $add('cod_purpose', $request->codReference !== '' ? $request->codReference : $request->reference, 14);
            $add('parcel_cod_type', in_array($this->setting('codSplit'), ['avg', 'all', 'firstonly'], true) ? $this->setting('codSplit') : 'firstonly');
        }

        if ($b2c) {
            $add('predict', 1);
        }

        if ($psd) {
            $add('pudo_id', $request->pointId, 7);
        }

        $add('dimension', $dimension);

        if ($this->boolSetting('sendSender')) {
            $add('sender_name', $from->company !== '' ? $from->company : $from->name, 30);
            $add('sender_street', $from->streetLine(), 35);
            $add('sender_city', $from->city, 35);
            $add('sender_pcode', $from->postcode, 9);
            $add('sender_country', strtoupper($from->countryCode), 2);
            $add('sender_phone', ltrim(Addresses::normalisePhone($from->phone), '+'), 30);
            $add('sender_email', $from->email, 100);
        }

        $response = $this->post('parcel/import', $params);

        if (!$response->ok()) {
            return ShipmentResult::fromResponse($response);
        }

        $status = strtolower(trim((string)$response->at('status', '')));
        $numbers = self::numbers($response->at('pl_number'));

        if ($status === 'err') {
            return ShipmentResult::rejected(Craft::t('carrier', 'DPD refused this: {error}', ['error' => self::errlog($response)]));
        }

        if ($status !== 'ok' || $numbers === []) {
            return ShipmentResult::uncertain(Craft::t('carrier', 'EasyShip answered without a parcel number. Check EasyShip for order {ref} before trying again.', ['ref' => $request->reference]));
        }

        // Now, not later: two minutes after this print, EasyShip only serves empty PDFs.
        $format = $this->labelFormatFor($request);
        $print = $this->print($numbers, $format);

        if ($print instanceof Label) {
            $single = count($numbers) === 1;
            $print->parcelIndex = $single ? 0 : null;
            $print->trackingNumber = $single ? $numbers[0] : '';

            return ShipmentResult::ok($numbers[0], $numbers, [$print], [
                'parcelNumbers' => $numbers,
                'country' => $this->country(),
                'parcelType' => $type,
                'cod' => $request->hasCod(),
                'printedAt' => time(),
            ]);
        }

        // The parcel exists but has no label, and an unprinted parcel can still be deleted.
        if ($this->delete($numbers) >= count($numbers)) {
            return ShipmentResult::failed(Craft::t('carrier', 'DPD created the parcel but did not return its label ({error}), so Carrier deleted it again. Try again.', ['error' => $print]));
        }

        return ShipmentResult::uncertain(Craft::t('carrier', 'DPD created parcel {numbers} but did not return its label ({error}), and it could not be deleted. Print it from EasyShip within two minutes of creation, or cancel it there.', [
            'numbers' => implode(', ', $numbers),
            'error' => $print,
        ]));
    }

    public function cancelShipment(ShipmentRef $shipment): ActionResult
    {
        $numbers = self::numbersOf($shipment);

        if ($numbers === []) {
            return ActionResult::failed(Craft::t('carrier', 'This shipment has no DPD parcel number.'));
        }

        // Unprinted parcels can be deleted outright; printed ones have to be cancelled.
        if ($this->delete($numbers) >= count($numbers)) {
            return ActionResult::ok(implode(',', $numbers), Craft::t('carrier', 'Deleted in EasyShip.'));
        }

        $cancelled = 0;
        $error = '';

        foreach (self::chunks($numbers) as $chunk) {
            $response = $this->post('parcel/cancel', [['parcels', implode(',', $chunk)]]);

            if (!$response->ok()) {
                return ActionResult::failed($response->errorMessage());
            }

            $done = self::leadingInt($response->at('status'));
            $cancelled += $done;

            if ($done < count($chunk)) {
                $error = self::errlog($response);
            }
        }

        if ($cancelled >= count($numbers)) {
            return ActionResult::ok(implode(',', $numbers), Craft::t('carrier', 'Cancelled in EasyShip.'));
        }

        return ActionResult::failed(Craft::t('carrier', 'DPD cancelled {done} of {total} parcels: {error}', [
            'done' => $cancelled,
            'total' => count($numbers),
            'error' => $error,
        ]));
    }

    /**
     * Ask EasyShip for the labels again. This only works in the first couple of minutes after
     * creation; after that EasyShip answers with an empty PDF, which is reported as a failure so
     * the engine keeps the label it stored at creation.
     */
    public function printLabels(array $shipments, string $format): LabelResult
    {
        $format = static::capabilities()->supportsLabelFormat($format) ? $format : static::capabilities()->getLabelFormats()[0];
        $numbers = [];

        foreach ($shipments as $shipment) {
            array_push($numbers, ...self::numbersOf($shipment));
        }

        $numbers = array_values(array_unique($numbers));

        if ($numbers === []) {
            return LabelResult::failed(Craft::t('carrier', 'These shipments have no DPD parcel numbers.'));
        }

        $labels = [];

        foreach (self::chunks($numbers) as $chunk) {
            $label = $this->print($chunk, $format);

            if (!$label instanceof Label) {
                return LabelResult::failed($label);
            }

            $label->trackingNumber = count($numbers) === 1 ? $numbers[0] : '';
            $labels[] = $label;
        }

        return LabelResult::ok($labels);
    }

    // =========================================================================
    // Tracking
    // =========================================================================

    /**
     * Latest status only, one parcel per call. Each status becomes an undated event, so it is
     * recorded once however often it is polled.
     */
    public function track(array $trackingNumbers): array
    {
        $out = [];

        foreach (array_unique(array_map('strval', $trackingNumbers)) as $number) {
            $response = $this->transport()->request('GET', 'parcel/status?' . self::query([['parcel_number', $number]]));

            if (!$response->ok()) {
                continue;
            }

            $raw = trim((string)$response->at('parcel_status', ''));

            if ($raw === '') {
                $out[] = new TrackingInfo(['trackingNumber' => $number, 'notFound' => true]);
                continue;
            }

            if (in_array(strtolower($raw), self::NO_CHANGE, true)) {
                $out[] = new TrackingInfo(['trackingNumber' => $number, 'status' => TrackingStatus::UNKNOWN]);
                continue;
            }

            $status = self::mapStatus($raw, self::STATUS_MAP, TrackingStatus::IN_TRANSIT);
            $text = self::humanise($raw);

            $out[] = new TrackingInfo([
                'trackingNumber' => $number,
                'status' => $status,
                'statusText' => $text,
                'events' => [new TrackingEvent([
                    'status' => $status,
                    'code' => $raw,
                    'description' => $text,
                ])],
            ]);
        }

        return $out;
    }

    // =========================================================================
    // Points, collections, end of day
    // =========================================================================

    /**
     * EasyShip has no point list. DPD hands merchants their PUDO ids directly; they are imported as
     * CSV, stored as manual rows, and never touched by a sync. Nothing to fetch, so no request.
     */
    public function pickupPoints(PointQuery $query): PointPage
    {
        return PointPage::of([]);
    }

    public function requestPickup(PickupRequest $request): ActionResult
    {
        $address = $request->address;

        if (!$address) {
            return ActionResult::failed(Craft::t('carrier', 'A DPD collection needs an address.'));
        }

        $zone = new DateTimeZone(self::ZONE);
        $day = $request->date ? (clone $request->date)->setTimezone($zone)->format('Y-m-d') : (new DateTime('now', $zone))->format('Y-m-d');
        $from = DateTime::createFromFormat('Y-m-d H:i', $day . ' ' . self::time($request->readyTime, '09:00'), $zone);
        $to = DateTime::createFromFormat('Y-m-d H:i', $day . ' ' . self::time($request->closeTime, '17:00'), $zone);
        $earliest = (new DateTime('now', $zone))->modify('+31 minutes');

        if ($from < $earliest) {
            $from = $earliest;
        }

        if ($to <= $from) {
            return ActionResult::failed(Craft::t('carrier', 'DPD needs at least 30 minutes’ notice, and the collection window on {date} has passed.', ['date' => $day]));
        }

        $params = [];

        foreach ([
            ['name', $address->company !== '' ? $address->company : $address->name],
            ['street', $address->streetLine()],
            ['postal', $address->postcode],
            ['city', $address->city],
            ['contact', $address->name],
            ['phone', ltrim(Addresses::normalisePhone($address->phone), '+')],
            ['parcels', max(1, $request->parcelCount)],
            ['remark', $request->instructions],
            ['date_from', $from->format('YmdHi')],
            ['date_to', $to->format('YmdHi')],
        ] as [$key, $value]) {
            if (trim((string)$value) !== '') {
                $params[] = [$key, mb_substr(trim((string)$value), 0, 35)];
            }
        }

        $response = $this->post('pickupdiff/import', $params);

        if (!$response->ok()) {
            return ActionResult::failed($response->errorMessage());
        }

        $reference = trim((string)$response->at('dispref', ''), " []");

        if ((string)$response->at('code', '') === '0' && $reference !== '') {
            return ActionResult::ok($reference, Craft::t('carrier', 'DPD will collect between {from} and {to}.', [
                'from' => $from->format('H:i'),
                'to' => $to->format('H:i'),
            ]));
        }

        return ActionResult::failed(Craft::t('carrier', 'DPD did not book the collection: {error}', [
            'error' => (string)($response->at('message') ?: self::errlog($response)),
        ]));
    }

    /**
     * Today's manifest for the driver, plus the COD manifest when any of the shipments carry cash.
     * DPD has nothing to close: EasyShip already sent the data. The manifest only lists parcels it
     * has sent, so it can be empty in the first minutes after labelling.
     */
    public function closeDay(array $shipments): ActionResult
    {
        $date = (new DateTime('now', new DateTimeZone(self::ZONE)))->format('Y-m-d');
        $types = ['manifest'];

        foreach ($shipments as $shipment) {
            if (!empty($shipment->data['cod'])) {
                $types[] = 'manifest_cod';
                break;
            }
        }

        $documents = [];

        foreach ($types as $type) {
            $response = $this->post('parcel/manifest', [['type', $type], ['date', $date]]);

            if (!$response->ok()) {
                return ActionResult::failed($response->errorMessage());
            }

            $problem = self::pdfProblem($response->body);

            if ($problem === null) {
                $documents[] = new Label(['kind' => 'manifest', 'format' => LabelFormat::PDF_A4, 'data' => $response->body]);
                continue;
            }

            if ($response->at('status') === 'err') {
                return ActionResult::failed(Craft::t('carrier', 'DPD would not produce the manifest: {error}', ['error' => self::errlog($response)]));
            }

            if ($type === 'manifest') {
                return ActionResult::failed(Craft::t('carrier', 'DPD returned an empty manifest for {date}. It lists only parcels whose data EasyShip has sent to DPD, which happens a couple of minutes after printing. Try again shortly.', ['date' => $date]));
            }
        }

        return ActionResult::ok('manifest-' . $date, Craft::t('carrier', 'EasyShip manifest for {date}.', ['date' => $date]), [
            'documents' => $documents,
        ]);
    }

    // =========================================================================
    // Plumbing
    // =========================================================================

    /** POST with every parameter in the query string and an empty body, as EasyShip wants. */
    private function post(string $path, array $params): Response
    {
        return $this->transport()->request('POST', $path . ($params !== [] ? '?' . self::query($params) : ''));
    }

    /**
     * `[[key, value], …]` → `key=value&…`, keys allowed to repeat, RFC 3986 encoded so a space is
     * `%20` and a `+` is `%2B`. Commas stay literal (RFC 3986 allows them in a query), because the
     * `parcels` list is comma-separated in every example in the manual.
     */
    private static function query(array $pairs): string
    {
        return implode('&', array_map(
            static fn(array $p) => rawurlencode((string)$p[0]) . '=' . str_replace('%2C', ',', rawurlencode((string)$p[1])),
            $pairs,
        ));
    }

    /**
     * Fetch labels as one document. Returns the label, or why there is none.
     */
    private function print(array $numbers, string $format): Label|string
    {
        $response = $this->post('parcel/print', [
            ['parcels', implode(',', $numbers)],
            ['format', match ($format) {
                LabelFormat::PDF_A6 => 'PDF_A6',
                LabelFormat::ZPL => 'ZPL',
                default => 'PDF_A4',
            }],
        ]);

        if (!$response->ok()) {
            return $response->errorMessage();
        }

        $body = $response->body;

        if (LabelFormat::bytesMatch(LabelFormat::ZPL, $body) && !str_starts_with($body, '%PDF')) {
            return new Label(['format' => LabelFormat::ZPL, 'data' => $body]);
        }

        $problem = self::pdfProblem($body);

        if ($problem === null) {
            return new Label(['format' => LabelFormat::isPdf($format) ? $format : LabelFormat::PDF_A6, 'data' => $body]);
        }

        if ($response->at('status') === 'err') {
            return self::errlog($response);
        }

        return Craft::t('carrier', 'DPD returned {problem} instead of a label. EasyShip stops serving labels about two minutes after they are first printed, once the parcel data has gone to DPD; the label Carrier saved when the shipment was created is unaffected.', ['problem' => $problem]);
    }

    /** How many parcels EasyShip says it deleted. Only unprinted parcels can be. */
    private function delete(array $numbers): int
    {
        $deleted = 0;

        foreach (self::chunks($numbers) as $chunk) {
            $response = $this->post('parcel/delete', [['parcels', implode(',', $chunk)]]);

            if ($response->ok()) {
                $deleted += self::leadingInt($response->at('status'));
            }
        }

        return $deleted;
    }

    /**
     * Null when the bytes are a PDF with something on it; otherwise a short description of what
     * came back instead. An "empty PDF" is how EasyShip answers a late reprint.
     */
    private static function pdfProblem(string $bytes): ?string
    {
        if ($bytes === '') {
            return Craft::t('carrier', 'nothing');
        }

        if (!str_starts_with(ltrim($bytes), '%PDF')) {
            return Craft::t('carrier', 'something that is not a PDF');
        }

        if (preg_match('#/Count\s+0\b#', $bytes)) {
            return Craft::t('carrier', 'an empty PDF');
        }

        // Pages can hide inside compressed object streams; only judge what is visible.
        if (!preg_match('#/Type\s*/Page\b(?!s)#', $bytes) && !str_contains($bytes, '/ObjStm')) {
            return Craft::t('carrier', 'an empty PDF');
        }

        return null;
    }

    /** Street and house number, separately — EasyShip refuses them in one field. */
    private static function streetAndNumber(Address $address): array
    {
        $street = trim($address->street1);
        $number = trim($address->houseNumber);

        if ($number === '') {
            if (preg_match('/^(.*\S)\s+(bb)\.?$/iu', $street, $m)) {
                return [$m[1], 'bb'];
            }

            [$street, $number] = Addresses::splitHouseNumber($street);
        }

        return [$street, $number];
    }

    /**
     * `LLLWWWHHH` in whole centimetres, zero-padded, from the largest parcel. Null when no parcel
     * has all three dimensions.
     */
    private static function dimension(ShipmentRequest $request): ?string
    {
        $largest = null;

        foreach ($request->parcels as $parcel) {
            if ($parcel->hasDimensions() && ($largest === null || $parcel->volumeMm3() > $largest->volumeMm3())) {
                $largest = $parcel;
            }
        }

        if ($largest === null) {
            return null;
        }

        $cm = static fn(int $mm) => str_pad((string)min(999, max(1, (int)Units::mmToCm($mm))), 3, '0', STR_PAD_LEFT);

        return $cm($largest->lengthMm) . $cm($largest->widthMm) . $cm($largest->heightMm);
    }

    private static function kg(int $grams): string
    {
        // A 0 g parcel would be refused; weigh it at the smallest amount EasyShip prints.
        return rtrim(rtrim(number_format(max(0.01, Units::gramsToKg($grams)), 2, '.', ''), '0'), '.');
    }

    private static function numbers(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        return array_values(array_filter(array_map(static fn($n) => is_scalar($n) ? trim((string)$n) : '', $value), static fn($n) => $n !== ''));
    }

    private static function numbersOf(ShipmentRef $shipment): array
    {
        $numbers = $shipment->trackingNumbers ?: self::numbers($shipment->data['parcelNumbers'] ?? []);

        return array_values(array_unique(array_filter(array_map('strval', $numbers), static fn($n) => $n !== '')));
    }

    /** Groups of numbers that fit EasyShip's 500-character `parcels` parameter. */
    private static function chunks(array $numbers): array
    {
        $chunks = [];
        $current = [];

        foreach ($numbers as $number) {
            if ($current !== [] && strlen(implode(',', [...$current, $number])) > self::PARCELS_PARAM_MAX) {
                $chunks[] = $current;
                $current = [];
            }

            $current[] = $number;
        }

        if ($current !== []) {
            $chunks[] = $current;
        }

        return $chunks;
    }

    private static function leadingInt(mixed $status): int
    {
        return is_scalar($status) && preg_match('/^\s*(\d+)/', (string)$status, $m) ? (int)$m[1] : 0;
    }

    /** EasyShip's error text, e.g. "Sender street is mandatory (ES-356)". */
    private static function errlog(Response $response): string
    {
        $log = $response->at('errlog');

        if (is_string($log) && trim($log) !== '') {
            return trim($log);
        }

        $status = $response->at('status');

        return is_string($status) && $status !== '' && $status !== 'err' ? $status : $response->errorMessage();
    }

    private static function humanise(string $status): string
    {
        return ucfirst(strtolower(str_replace('_', ' ', trim($status))));
    }

    private static function time(string $value, string $fallback): string
    {
        return preg_match('/^([01]?\d|2[0-3]):[0-5]\d$/', trim($value)) ? str_pad(trim($value), 5, '0', STR_PAD_LEFT) : $fallback;
    }

    private function country(): string
    {
        $country = strtolower((string)$this->setting('country', 'hr'));

        return isset(self::COUNTRIES[$country]) ? $country : 'hr';
    }
}
