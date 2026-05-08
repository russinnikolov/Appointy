<?php

namespace App\Util;

class PhoneValidator
{
    public static function isValid(string $phone): bool
    {
        // Accepts optional leading +, then digits, spaces, dashes, dots, parentheses — 7–20 chars total
        return (bool) preg_match('/^\+?[\d\s\-\.\(\)]{7,20}$/', $phone);
    }
}
