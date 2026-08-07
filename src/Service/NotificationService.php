<?php

namespace App\Service;

use App\Entity\Appointment;
use App\Enum\NotificationChannelType;
use App\Repository\NotificationChannelRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Notifies a business about events (currently: a new pending reservation) through
 * whichever channel(s) it has connected. Every send is best-effort — a notification
 * failure must never break the booking flow that triggered it.
 */
class NotificationService
{
    public function __construct(
        private readonly NotificationChannelRepository $channelRepo,
        private readonly TelegramClient $telegram,
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function notifyNewReservation(Appointment $appt): void
    {
        $channel = $this->channelRepo->findOneActiveByOrgAndType($appt->getOrganization(), NotificationChannelType::TELEGRAM);
        if (!$channel || !$channel->isConnected()) {
            return;
        }

        try {
            $text = $this->buildReservationMessage($appt);
            $keyboard = [[
                ['text' => '✅ Потвърди', 'callback_data' => 'confirm:' . $appt->getId()],
                ['text' => '❌ Откажи',   'callback_data' => 'decline:' . $appt->getId()],
            ]];

            $sent = $this->telegram->sendMessage($channel->getExternalId(), $text, $keyboard);

            $appt->setTelegramNotifiedChatId((string) $sent['chat_id'])
                 ->setTelegramNotifiedMessageId((string) $sent['message_id']);
            $this->em->flush();
        } catch (\Throwable) {
            // Never let a notification failure break the booking that triggered it.
        }
    }

    private function buildReservationMessage(Appointment $appt): string
    {
        $lines = [
            '📅 <b>Нова резервация</b>',
            sprintf('Клиент: %s', htmlspecialchars($appt->getBookerName())),
        ];

        if ($appt->getBookerPhone()) {
            $lines[] = sprintf('Телефон: %s', htmlspecialchars($appt->getBookerPhone()));
        }
        if ($appt->getService()) {
            $lines[] = sprintf('Услуга: %s', htmlspecialchars($appt->getService()->getName()));
        }

        $lines[] = sprintf('Дата: %s', $appt->getAppointmentDate()->format('d.m.Y'));
        $lines[] = sprintf('Час: %s', $appt->getAppointmentTime()->format('H:i'));

        if ($appt->getNotes()) {
            $lines[] = sprintf('Бележка: %s', htmlspecialchars($appt->getNotes()));
        }

        return implode("\n", $lines);
    }
}
