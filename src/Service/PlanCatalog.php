<?php

namespace App\Service;

use App\Enum\PlanCode;

/**
 * Static pricing/limit definitions for the 4 subscription plans.
 * All amounts in cents (Stripe-compatible, avoids float rounding).
 */
final class PlanCatalog
{
    public const RESERVATION_PRICE_CENTS  = 200;    // 2.00 EUR per confirmed reservation (pay-per-reservation plan)
    public const SMALL_MONTHLY_CENTS      = 5000;   // 50 EUR
    public const MEDIUM_MONTHLY_CENTS     = 10000;  // 100 EUR
    public const ENTERPRISE_BASE_CENTS    = 50000;  // 500 EUR, includes up to 50 employees
    public const ENTERPRISE_INCLUDED_EMPLOYEES = 50;
    public const ENTERPRISE_OVERAGE_CENTS = 2000;   // 20 EUR per employee beyond the included 50

    /** Max employees allowed on the plan, or null if unlimited (unlimited plans may still bill overage, e.g. enterprise). */
    public static function employeeLimit(PlanCode $plan): ?int
    {
        return match ($plan) {
            PlanCode::PAY_PER_RESERVATION => null,
            PlanCode::SMALL_BUSINESS      => 5,
            PlanCode::MEDIUM_BUSINESS     => 15,
            PlanCode::ENTERPRISE          => null,
        };
    }

    /** Flat monthly base amount in cents. Pay-per-reservation has no base fee. */
    public static function baseMonthlyCents(PlanCode $plan): int
    {
        return match ($plan) {
            PlanCode::PAY_PER_RESERVATION => 0,
            PlanCode::SMALL_BUSINESS      => self::SMALL_MONTHLY_CENTS,
            PlanCode::MEDIUM_BUSINESS     => self::MEDIUM_MONTHLY_CENTS,
            PlanCode::ENTERPRISE          => self::ENTERPRISE_BASE_CENTS,
        };
    }

    /** Computes the amount due (in cents) for a billing period. */
    public static function amountDueCents(PlanCode $plan, int $confirmedReservationCount, int $employeeCount): int
    {
        return match ($plan) {
            PlanCode::PAY_PER_RESERVATION => $confirmedReservationCount * self::RESERVATION_PRICE_CENTS,
            PlanCode::SMALL_BUSINESS      => self::SMALL_MONTHLY_CENTS,
            PlanCode::MEDIUM_BUSINESS     => self::MEDIUM_MONTHLY_CENTS,
            PlanCode::ENTERPRISE          => self::ENTERPRISE_BASE_CENTS
                + max(0, $employeeCount - self::ENTERPRISE_INCLUDED_EMPLOYEES) * self::ENTERPRISE_OVERAGE_CENTS,
        };
    }

    public static function label(PlanCode $plan): string
    {
        return match ($plan) {
            PlanCode::PAY_PER_RESERVATION => 'Pay per reservation',
            PlanCode::SMALL_BUSINESS      => 'Small business',
            PlanCode::MEDIUM_BUSINESS     => 'Medium business',
            PlanCode::ENTERPRISE          => 'Enterprise',
        };
    }
}
