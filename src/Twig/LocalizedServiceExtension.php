<?php

namespace App\Twig;

use Symfony\Component\HttpFoundation\RequestStack;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

/**
 * Provides the `localized` Twig filter for translatable entities
 * (Service, Organization — anything with getLocalized(field, locale)).
 *
 * Usage:
 *   {{ service|localized('name') }}
 *   {{ org|localized('name') }}
 *   {{ org|localized('description') }}
 *
 * Falls back to the raw original field if the translated column is empty.
 */
class LocalizedServiceExtension extends AbstractExtension
{
    public function __construct(private readonly RequestStack $requestStack) {}

    public function getFilters(): array
    {
        return [
            new TwigFilter('localized', [$this, 'localized']),
        ];
    }

    public function localized(object $entity, string $field): string
    {
        $locale = $this->requestStack->getCurrentRequest()?->getLocale() ?? 'en';

        // Try the translated column for the active locale
        if (method_exists($entity, 'getLocalized')) {
            $translated = $entity->getLocalized($field, $locale);
            if ($translated !== null && $translated !== '') {
                return $translated;
            }
        }

        // Fall back to the original value
        $getter = 'get' . ucfirst($field);
        return method_exists($entity, $getter) ? ($entity->$getter() ?? '') : '';
    }
}
