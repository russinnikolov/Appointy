<?php

namespace App\Controller;

use App\Entity\Appointment;
use App\Enum\NotificationChannelType;
use App\Repository\AppointmentRepository;
use App\Repository\NotificationChannelRepository;
use App\Service\AppointmentDecisionService;
use App\Service\TelegramClient;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

class TelegramWebhookController extends AbstractController
{
    public function __construct(
        private readonly string $webhookSecret,
    ) {
    }

    #[Route('/webhooks/telegram', name: 'telegram_webhook', methods: ['POST'])]
    public function __invoke(
        Request $request,
        NotificationChannelRepository $channelRepo,
        AppointmentRepository $apptRepo,
        AppointmentDecisionService $decisions,
        TelegramClient $telegram,
        EntityManagerInterface $em,
        TranslatorInterface $t,
    ): Response {
        if ($request->headers->get('X-Telegram-Bot-Api-Secret-Token') !== $this->webhookSecret) {
            return new Response('', 403);
        }

        $update = json_decode($request->getContent(), true) ?? [];

        try {
            if (isset($update['message']['text'])) {
                $this->handleMessage($update['message'], $channelRepo, $telegram, $em);
            } elseif (isset($update['callback_query'])) {
                $this->handleCallbackQuery($update['callback_query'], $channelRepo, $apptRepo, $decisions, $telegram, $t);
            }
        } catch (\Throwable) {
            // Swallow — Telegram retries on non-200, and a broken update should not loop forever.
        }

        return new Response('', 200);
    }

    private function handleMessage(array $message, NotificationChannelRepository $channelRepo, TelegramClient $telegram, EntityManagerInterface $em): void
    {
        $text = trim($message['text']);
        if (!str_starts_with($text, '/start ')) {
            return;
        }

        $token   = trim(substr($text, 7));
        $channel = $channelRepo->findOneByLinkToken($token);
        if (!$channel) {
            return;
        }

        $chatId = (string) $message['chat']['id'];
        $channel->setExternalId($chatId)
                ->setLinkToken(null)
                ->setIsActive(true);
        $em->flush();

        $telegram->sendMessage($chatId, '✅ Свързано! Ще получавате известия за нови резервации тук.');
    }

    private function handleCallbackQuery(
        array $callback,
        NotificationChannelRepository $channelRepo,
        AppointmentRepository $apptRepo,
        AppointmentDecisionService $decisions,
        TelegramClient $telegram,
        TranslatorInterface $t,
    ): void {
        $callbackId = $callback['id'];
        $data       = $callback['data'] ?? '';
        $chatId     = (string) ($callback['message']['chat']['id'] ?? '');
        $messageId  = (string) ($callback['message']['message_id'] ?? '');

        if (!preg_match('/^(confirm|decline):(\d+)$/', $data, $m)) {
            $telegram->answerCallbackQuery($callbackId);
            return;
        }

        [, $action, $apptId] = $m;
        $appt = $apptRepo->find((int) $apptId);

        if (!$appt || $appt->getStatus() !== Appointment::STATUS_PENDING) {
            $telegram->answerCallbackQuery($callbackId, 'Тази резервация вече е обработена.', true);
            return;
        }

        $channel = $channelRepo->findOneActiveByOrgAndType($appt->getOrganization(), NotificationChannelType::TELEGRAM);
        if (!$channel || $channel->getExternalId() !== $chatId) {
            $telegram->answerCallbackQuery($callbackId);
            return;
        }

        if ($action === 'confirm') {
            if (!$decisions->confirm($appt)) {
                $telegram->answerCallbackQuery($callbackId, 'Този час вече е запълнен.', true);
                return;
            }
            $telegram->answerCallbackQuery($callbackId, 'Резервацията е потвърдена ✅');
        } else {
            $decisions->cancel($appt, $t->trans('flash.cancelled_via_telegram'));
            $telegram->answerCallbackQuery($callbackId, 'Резервацията е отказана ❌');
        }

        if ($chatId && $messageId) {
            $status = $action === 'confirm' ? '✅ Потвърдено' : '❌ Отказано';
            $summary = sprintf(
                "%s\n%s, %s %s",
                htmlspecialchars($appt->getBookerName()),
                $appt->getAppointmentDate()->format('d.m.Y'),
                $appt->getAppointmentTime()->format('H:i'),
                $status
            );
            $telegram->editMessageText($chatId, $messageId, $summary);
        }
    }
}
