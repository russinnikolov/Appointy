<?php

namespace App\EventSubscriber;

use App\Entity\User;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

class MustChangePasswordSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private TokenStorageInterface $tokenStorage,
        private RouterInterface $router,
    ) {}

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $token = $this->tokenStorage->getToken();
        if (!$token) {
            return;
        }

        $user = $token->getUser();
        if (!$user instanceof User || !$user->isMustChangePassword()) {
            return;
        }

        $route   = $event->getRequest()->attributes->get('_route', '');
        $allowed = ['employee_profile', 'app_logout'];

        if (in_array($route, $allowed, true) || !str_starts_with($route, 'employee_')) {
            return;
        }

        $event->getRequest()->getSession()->getFlashBag()->add(
            'warning',
            'Please set a new password before continuing.'
        );
        $event->setResponse(new RedirectResponse($this->router->generate('employee_profile')));
    }

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::REQUEST => ['onKernelRequest', 8]];
    }
}
