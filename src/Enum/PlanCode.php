<?php

namespace App\Enum;

enum PlanCode: string
{
    case PAY_PER_RESERVATION = 'pay_per_reservation';
    case SMALL_BUSINESS      = 'small_business';
    case MEDIUM_BUSINESS     = 'medium_business';
    case ENTERPRISE          = 'enterprise';
}
