<?php

namespace App\EventSubscriber;

use App\Entity\PageView;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class VisitorTrackingSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private EntityManagerInterface $em,
        private HttpClientInterface    $httpClient,
    ) {}

    public function onKernelTerminate(TerminateEvent $event): void
    {
        $request = $event->getRequest();
        $route   = $request->attributes->get('_route', '');

        // Skip profiler, debug toolbar, API and asset routes
        if (
            str_starts_with($route, '_')        ||
            str_starts_with($route, 'api_')     ||
            str_starts_with($route, 'set_locale') ||
            str_contains($request->getPathInfo(), '/_')
        ) {
            return;
        }

        $ip = $request->getClientIp();
        $ua = $request->headers->get('User-Agent', '');

        [$country, $city] = $this->geolocate($ip);

        $view = new PageView();
        $view->setIp($ip)
             ->setCountry($country)
             ->setCity($city)
             ->setBrowser($this->parseBrowser($ua))
             ->setOs($this->parseOs($ua))
             ->setDevice($this->parseDevice($ua))
             ->setRoute($route ?: null)
             ->setUrl((string) $request->getUri())
             ->setReferer($request->headers->get('Referer'))
             ->setLocale($request->getLocale());

        $token = $request->attributes->get('_security_token');
        $user  = $request->attributes->get('_security_last_username')
            ? null
            : ($event->getResponse()->headers->has('X-User-Id') ? null : null);

        // Attempt to grab user ID from the security token stored on request
        try {
            $tokenStorage = $request->attributes->get('_security_token');
            if ($tokenStorage instanceof \Symfony\Component\Security\Core\Authentication\Token\TokenInterface) {
                $u = $tokenStorage->getUser();
                if ($u instanceof User) {
                    $view->setUserId($u->getId());
                }
            }
        } catch (\Throwable) {}

        $this->em->persist($view);
        $this->em->flush();
    }

    private function geolocate(?string $ip): array
    {
        if (!$ip || in_array($ip, ['127.0.0.1', '::1', ''], true)) {
            return [null, null];
        }

        try {
            $resp = $this->httpClient->request('GET', "http://ip-api.com/json/{$ip}", [
                'timeout' => 2.0,
                'query'   => ['fields' => 'country,city,status'],
            ]);
            $data = $resp->toArray(false);

            if (($data['status'] ?? '') === 'success') {
                return [$data['country'] ?? null, $data['city'] ?? null];
            }
        } catch (\Throwable) {}

        return [null, null];
    }

    private function parseBrowser(string $ua): string
    {
        if (str_contains($ua, 'Edg/'))    return 'Edge';
        if (str_contains($ua, 'OPR/') || str_contains($ua, 'Opera')) return 'Opera';
        if (str_contains($ua, 'Chrome/')) return 'Chrome';
        if (str_contains($ua, 'Firefox/')) return 'Firefox';
        if (str_contains($ua, 'Safari/') && str_contains($ua, 'Version/')) return 'Safari';
        return 'Other';
    }

    private function parseOs(string $ua): string
    {
        if (str_contains($ua, 'Android')) return 'Android';
        if (str_contains($ua, 'iPhone') || str_contains($ua, 'iPad')) return 'iOS';
        if (str_contains($ua, 'Windows NT')) return 'Windows';
        if (str_contains($ua, 'Mac OS X')) return 'macOS';
        if (str_contains($ua, 'Linux')) return 'Linux';
        return 'Other';
    }

    private function parseDevice(string $ua): string
    {
        if (str_contains($ua, 'Tablet') || str_contains($ua, 'iPad')) return 'tablet';
        if (str_contains($ua, 'Mobile') || str_contains($ua, 'Android') || str_contains($ua, 'iPhone')) return 'mobile';
        return 'desktop';
    }

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::TERMINATE => 'onKernelTerminate'];
    }
}
