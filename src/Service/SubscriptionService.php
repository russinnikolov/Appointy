<?php

namespace App\Service;

use App\Entity\Organization;
use App\Entity\Subscription;

/**
 * Single source of truth for plan-based access control: whether an organization
 * can currently receive reservations, and whether it can add another employee.
 */
class SubscriptionService
{
    public function canAcceptReservations(Organization $org): bool
    {
        $sub = $org->getSubscription();
        if (!$sub) {
            // No subscription row (shouldn't happen post-backfill/registration wiring) — don't break booking.
            return true;
        }
        if ($sub->isTrialing()) {
            return true;
        }

        return $sub->getStatus() === Subscription::STATUS_ACTIVE;
    }

    /** Max employees allowed, or null if unlimited (trial or a plan without a hard cap). */
    public function employeeLimit(Organization $org): ?int
    {
        $sub = $org->getSubscription();
        if (!$sub || $sub->isTrialing()) {
            return null;
        }

        return PlanCatalog::employeeLimit($sub->getPlan());
    }

    public function canAddEmployee(Organization $org, int $currentEmployeeCount): bool
    {
        $limit = $this->employeeLimit($org);

        return $limit === null || $currentEmployeeCount < $limit;
    }
}
