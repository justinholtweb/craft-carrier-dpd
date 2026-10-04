<?php

namespace justinholtweb\carrierdpd;

use craft\base\Plugin as BasePlugin;
use craft\events\RegisterComponentTypesEvent;
use justinholtweb\carrier\services\Carriers;
use justinholtweb\carrierdpd\carriers\DpdEasyShipCarrier;
use yii\base\Event;

/**
 * Carrier for DPD.
 *
 * A carrier add-on and nothing else: no tables, no settings screen, no control panel section.
 * Carrier owns packing, checkout, label storage, the claim that stops a label being bought twice,
 * tracking, the pickup-point picker and the log; this package translates one API. That is why it
 * is free.
 */
class Plugin extends BasePlugin
{
    public string $schemaVersion = '5.0.0';

    public bool $hasCpSettings = false;

    public bool $hasCpSection = false;

    public function init(): void
    {
        parent::init();

        Event::on(
            Carriers::class,
            Carriers::EVENT_REGISTER_CARRIERS,
            static function(RegisterComponentTypesEvent $event) {
                $event->types[] = DpdEasyShipCarrier::class;
            },
        );
    }
}
