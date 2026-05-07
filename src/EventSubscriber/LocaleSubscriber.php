<?php

namespace App\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class LocaleSubscriber implements EventSubscriberInterface
{
    private const SUPPORTED = ['bg', 'en'];
    private const DEFAULT   = 'bg';

    public function __construct(private HttpClientInterface $httpClient) {}

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $session = $request->getSession();

        // Explicit switch via query param ?_locale=en
        $param = $request->query->get('_locale');
        if ($param && in_array($param, self::SUPPORTED, true)) {
            $session->set('_locale', $param);
            $request->setLocale($param);
            return;
        }

        // Stored in session from a previous visit
        if ($session->has('_locale')) {
            $request->setLocale($session->get('_locale'));
            return;
        }

        $locale = $this->detect($request->getClientIp(), $request->getLanguages());
        $session->set('_locale', $locale);
        $request->setLocale($locale);
    }

    private function detect(?string $ip, array $acceptLanguages): string
    {
        // If browser explicitly prefers Bulgarian, trust it
        foreach ($acceptLanguages as $lang) {
            if (str_starts_with(strtolower($lang), 'bg')) {
                return 'bg';
            }
        }

        // IP geolocation: visitors from Bulgaria → Bulgarian
        if ($ip && !in_array($ip, ['127.0.0.1', '::1'], true)) {
            try {
                $resp = $this->httpClient->request('GET', "http://ip-api.com/json/{$ip}", [
                    'timeout' => 1.5,
                    'query'   => ['fields' => 'countryCode,status'],
                ]);
                $data = $resp->toArray(false);
                if (($data['status'] ?? '') === 'success' && ($data['countryCode'] ?? '') === 'BG') {
                    return 'bg';
                }
                // Foreign IP with English browser → English
                foreach ($acceptLanguages as $lang) {
                    if (str_starts_with(strtolower($lang), 'en')) {
                        return 'en';
                    }
                }
            } catch (\Throwable) {}
        }

        return self::DEFAULT;
    }

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::REQUEST => ['onKernelRequest', 20]];
    }
}
