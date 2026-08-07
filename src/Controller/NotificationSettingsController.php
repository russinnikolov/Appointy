<?php

namespace App\Controller;

use App\Entity\NotificationChannel;
use App\Entity\User;
use App\Enum\NotificationChannelType;
use App\Repository\NotificationChannelRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

#[IsGranted('ROLE_BUSINESS')]
#[Route('/business/settings/notifications')]
class NotificationSettingsController extends AbstractController
{
    public function __construct(
        private readonly string $telegramBotUsername,
    ) {
    }

    #[Route('', name: 'business_notifications')]
    public function index(NotificationChannelRepository $channelRepo): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $org  = $user->getOrganization();

        if (!$org) {
            return $this->redirectToRoute('business_dashboard');
        }

        $telegram = $channelRepo->findOneByOrgAndType($org, NotificationChannelType::TELEGRAM);

        return $this->render('business/notifications.html.twig', [
            'organization'  => $org,
            'telegram'      => $telegram,
            'telegramLink'  => $telegram && $telegram->getLinkToken()
                ? sprintf('https://t.me/%s?start=%s', $this->telegramBotUsername, $telegram->getLinkToken())
                : null,
        ]);
    }

    #[Route('/telegram/connect', name: 'business_notifications_telegram_connect', methods: ['POST'])]
    public function connectTelegram(NotificationChannelRepository $channelRepo, EntityManagerInterface $em, TranslatorInterface $t): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $org  = $user->getOrganization();

        if (!$org) {
            return $this->redirectToRoute('business_dashboard');
        }

        $channel = $channelRepo->findOneByOrgAndType($org, NotificationChannelType::TELEGRAM);
        if (!$channel) {
            $channel = new NotificationChannel();
            $channel->setOrganization($org)->setType(NotificationChannelType::TELEGRAM);
            $em->persist($channel);
        }

        $channel->setExternalId(null)
                ->setLinkToken(bin2hex(random_bytes(16)));
        $em->flush();

        $this->addFlash('success', $t->trans('flash.telegram_link_generated'));

        return $this->redirectToRoute('business_notifications');
    }

    #[Route('/telegram/disconnect', name: 'business_notifications_telegram_disconnect', methods: ['POST'])]
    public function disconnectTelegram(NotificationChannelRepository $channelRepo, EntityManagerInterface $em, TranslatorInterface $t): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $org  = $user->getOrganization();

        $channel = $org ? $channelRepo->findOneByOrgAndType($org, NotificationChannelType::TELEGRAM) : null;
        if ($channel) {
            $em->remove($channel);
            $em->flush();
            $this->addFlash('success', $t->trans('flash.telegram_disconnected'));
        }

        return $this->redirectToRoute('business_notifications');
    }
}
