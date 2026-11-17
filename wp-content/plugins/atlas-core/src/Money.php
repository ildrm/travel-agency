<?php
namespace Atlas\Core;
if (!defined('ABSPATH')) { exit; }
final class Money {
    public static function irr_to_toman(int $irr): string {
        $sign = $irr < 0 ? '-' : ''; $absolute = abs($irr);
        $whole = intdiv($absolute, 10); $remainder = $absolute % 10;
        return $sign . $whole . ($remainder ? '.' . $remainder : '');
    }
}
