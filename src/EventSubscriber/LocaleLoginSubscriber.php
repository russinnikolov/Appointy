<?php

namespace App\EventSubscriber;

use App\Entity\User;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;

/**
 * Right after a successful login, copy the user's persisted locale preference
 * into the session so that the very next request (the post-login redirect) uses
 * the correct language — even on a fresh session or a different device.
 */
class LocaleLoginSubscriber implements EventSubscriberInterface
{
    public function onLoginSuccess(LoginSuccessEvent $event): void
    {
        $user = $event->getAuthenticatedToken()->getUser();
        if (!$user instanceof User || $user->getLocale() === null) {
            return;
        }

        $event->getRequest()->getSession()->set('_locale', $user->getLocale());
    }

    public static function getSubscribedEvents(): array
    {
        return [LoginSuccessEvent::class => 'onLoginSuccess'];
    }
}
