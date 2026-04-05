<?php

namespace app\components;

class AddressParser
{
    public static function parseIsraeliAddress(?string $address): array
    {
        $address = trim((string)$address);
        if ($address === '') {
            return [
                'raw' => null,
                'street' => null,
                'house_number' => null,
                'city' => null,
            ];
        }

        // פיצול לפי פסיקים
        $parts = array_values(array_filter(array_map('trim', explode(',', $address))));

        // ❌ סילוק ZIP (חלקים שהם רק ספרות)
        $parts = array_values(array_filter($parts, function ($p) {
            return !preg_match('/^\d{5,}$/', $p); // 5+ ספרות = מיקוד
        }));

        $streetPart = $parts[0] ?? null;
        $city = $parts[count($parts) - 1] ?? null;

        $street = $streetPart;
        $houseNumber = null;

        if ($streetPart) {
            // "שביל הגיחון 1" / "דיזנגוף 50A"
            if (preg_match('/^(.*?)[\s,]+(\d+[A-Za-zא-ת]?)$/u', $streetPart, $m)) {
                $street = trim($m[1]);
                $houseNumber = trim($m[2]);
            }
        }

        return [
            'raw' => $address,
            'street' => $street ?: null,
            'house_number' => $houseNumber,
            'city' => $city ?: null,
        ];
    }

}
