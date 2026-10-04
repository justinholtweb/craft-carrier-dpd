<?php
/**
 * Recorded happy-path answers for the Carrier conformance suite.
 *
 * Shapes follow the DPD EasyShip Webservice User Manual v2.2: every parameter in the query string,
 * JSON answers with a free-text `status`, labels and manifests as raw octet-stream bytes. The void
 * answers exercise the documented path for a printed parcel: `delete` refuses it ("No matched
 * parcels" / "1 not deleted") and `cancel` takes it.
 */

use justinholtweb\carrier\base\Response;
use justinholtweb\carrier\helpers\Pdf;

return [
    'dpd-easyship' => static function(string $method, string $url, array $options): Response {
        $path = (string)parse_url($url, PHP_URL_PATH);
        $query = [];

        // Repeated keys (weight=…&weight=…) survive; parse_str would collapse them.
        foreach (explode('&', (string)parse_url($url, PHP_URL_QUERY)) as $pair) {
            if ($pair !== '') {
                [$key, $value] = array_pad(explode('=', $pair, 2), 2, '');
                $query[rawurldecode($key)][] = rawurldecode($value);
            }
        }

        $one = static fn(string $key) => $query[$key][0] ?? '';
        $parcels = array_filter(explode(',', $one('parcels')));
        $bytes = static fn(string $body) => new Response(200, ['Content-Type' => ['application/octet-stream'], 'easyship-backend-version' => ['2.0.45']], $body);

        return match (true) {
            str_ends_with($path, '/parcel/import') && $method === 'POST' => Response::json(200, [
                'status' => 'ok',
                'errlog' => null,
                'pl_number' => array_map(static fn($i) => (string)(16972000336597 + $i), range(0, max(1, (int)$one('num_of_parcel')) - 1)),
            ]),
            str_ends_with($path, '/parcel/print') && $method === 'POST' => $bytes($one('format') === 'ZPL'
                ? implode("\n", array_map(static fn($n) => "^XA^FO40,40^A0N,40,40^FDDPD $n^FS^XZ", $parcels))
                : Pdf::text(array_merge(['DPD'], $parcels), $one('format') === 'PDF_A6' ? 297.6 : 595.3, $one('format') === 'PDF_A6' ? 419.5 : 841.9)),
            str_ends_with($path, '/parcel/delete') => Response::json(200, ['status' => 'No matched parcels', 'errlog' => count($parcels) . ' not deleted']),
            str_ends_with($path, '/parcel/cancel') => Response::json(200, ['status' => count($parcels) . ' cancelled', 'errlog' => null]),
            str_ends_with($path, '/parcel/status') && $method === 'GET' => $one('parcel_number') === '00000000000000'
                ? Response::json(200, ['parcel_status' => null])
                : Response::json(200, ['parcel_status' => 'OUT_FOR_DELIVERY']),
            str_ends_with($path, '/pickupdiff/import') => Response::json(200, ['code' => 0, 'message' => 'OK', 'dispref' => 'ES2610040001']),
            str_ends_with($path, '/parcel/manifest') => $bytes(Pdf::text(['DPD MANIFEST ' . $one('date'), 'Type: ' . $one('type')], 595.3, 841.9)),
            default => Response::json(404, ['status' => 'err', 'errlog' => 'Not found']),
        };
    },
];
