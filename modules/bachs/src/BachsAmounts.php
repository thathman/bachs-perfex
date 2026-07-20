<?php

namespace Perfexcrm\Bachs;

defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Ported directly from services/gateway/src/webhooks/bachs-webhook.service.ts
 * (toMinorUnits/toMajorUnitsString), confirmed against docs.bachs.io during
 * the original TypeScript build (2026-07-11). Bachs amounts are decimal
 * strings in MAJOR units ("75000.00"); string-based conversion avoids the
 * float rounding errors Math.round(parseFloat(x) * 100) can introduce.
 */
class BachsAmounts
{
    public static function toMinorUnits(string $amountStr): int
    {
        $parts = explode('.', $amountStr, 2);
        $whole = $parts[0];
        $fraction = $parts[1] ?? '';
        $cents = substr($fraction . '00', 0, 2);

        return ((int) $whole) * 100 + ((int) $cents);
    }

    public static function toMajorUnitsString(int $minor): string
    {
        $sign  = $minor < 0 ? '-' : '';
        $abs   = abs($minor);
        $whole = intdiv($abs, 100);
        $cents = str_pad((string) ($abs % 100), 2, '0', STR_PAD_LEFT);

        return "{$sign}{$whole}.{$cents}";
    }
}
