<?php

namespace App\Service;

use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Wraps the LibreTranslate REST API with a 30-day filesystem cache so
 * identical strings are never translated twice.
 */
class TranslationApiService
{
    /** Locales the app supports. Add more here if the app ever gains new languages. */
    public const LOCALES = ['en', 'bg'];

    private FilesystemAdapter $cache;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly string $libreTranslateUrl,
        private readonly string $libreTranslateApiKey = '',
    ) {
        $this->cache = new FilesystemAdapter('libretranslate', 86400 * 30);
    }

    /**
     * Translate $text from $source locale to $target locale.
     * Returns the original text on any failure so the app always has a value.
     */
    public function translate(string $text, string $source, string $target): string
    {
        if ($source === $target || trim($text) === '') {
            return $text;
        }

        $cacheKey = 'tr_' . $source . '_' . $target . '_' . md5($text);

        try {
            return $this->cache->get($cacheKey, function (ItemInterface $item) use ($text, $source, $target) {
                $item->expiresAfter(86400 * 30);

                $payload = [
                    'q'      => $text,
                    'source' => $source,
                    'target' => $target,
                    'format' => 'text',
                ];

                if ($this->libreTranslateApiKey !== '') {
                    $payload['api_key'] = $this->libreTranslateApiKey;
                }

                $response = $this->httpClient->request(
                    'POST',
                    rtrim($this->libreTranslateUrl, '/') . '/translate',
                    ['json' => $payload, 'timeout' => 10]
                );

                return $response->toArray()['translatedText'] ?? $text;
            });
        } catch (\Throwable) {
            // Network error, rate limit, bad response — fall back to original
            return $text;
        }
    }

    /**
     * Translate name + description of a Service into every supported locale
     * except the one it was written in, then store results back on the entity.
     */
    public function translateService(\App\Entity\Service $service, string $sourceLocale): void
    {
        $this->translateEntity($service, $sourceLocale, $service->getName(), $service->getDescription());
    }

    /**
     * Translate name + description of an Organization into every supported locale.
     */
    public function translateOrg(\App\Entity\Organization $org, string $sourceLocale): void
    {
        $this->translateEntity($org, $sourceLocale, $org->getName(), $org->getDescription());
    }

    /**
     * Generic helper: translates name+description on any entity that implements
     * setSourceLocale / setTranslatedName / setTranslatedDescription.
     */
    private function translateEntity(object $entity, string $sourceLocale, ?string $name, ?string $description): void
    {
        $entity->setSourceLocale($sourceLocale);

        // Store the original text into its own locale column
        $entity->setTranslatedName($sourceLocale, $name);
        $entity->setTranslatedDescription($sourceLocale, $description);

        foreach (self::LOCALES as $target) {
            if ($target === $sourceLocale) {
                continue;
            }

            if ($name) {
                $entity->setTranslatedName(
                    $target,
                    $this->translate($name, $sourceLocale, $target)
                );
            }

            if ($description) {
                $entity->setTranslatedDescription(
                    $target,
                    $this->translate($description, $sourceLocale, $target)
                );
            }
        }
    }
}
