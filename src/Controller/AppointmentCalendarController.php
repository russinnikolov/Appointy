<?php

namespace App\Controller;

use App\Entity\Appointment;
use App\Repository\EmployeeRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

class AppointmentCalendarController extends AbstractController
{
    #[Route('/appointment/{id}/ical', name: 'appointment_ical', methods: ['GET'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function ical(Appointment $appointment, EmployeeRepository $empRepo): Response
    {
        $user = $this->getUser();
        $allowed = false;

        if ($this->isGranted('ROLE_BUSINESS')) {
            $allowed = $appointment->getOrganization() === $user->getOrganization();
        } elseif ($this->isGranted('ROLE_EMPLOYEE')) {
            $emp = $empRepo->findOneBy(['user' => $user]);
            $allowed = $emp && $appointment->getEmployee() === $emp;
        } else {
            $allowed = $appointment->getClient() === $user;
        }

        if (!$allowed) {
            throw $this->createAccessDeniedException();
        }

        $org  = $appointment->getOrganization();
        $emp  = $appointment->getEmployee();

        $date    = $appointment->getAppointmentDate()->format('Ymd');
        $timeObj = \DateTime::createFromInterface($appointment->getAppointmentTime());
        $timeStr = $timeObj->format('His');
        $timeObj->modify('+1 hour');
        $endStr  = $timeObj->format('His');

        $now     = (new \DateTime('now', new \DateTimeZone('UTC')))->format('Ymd\THis\Z');
        $uid     = 'appointment-' . $appointment->getId() . '@grafira';
        $summary = ($org?->getName() ?? 'Appointment') . ($emp ? ' — ' . $emp->getName() : '');

        $descParts = [$appointment->getBookerName()];
        if ($emp) {
            $descParts[] = $emp->getName() . ($emp->getRole() ? ' (' . $emp->getRole() . ')' : '');
        }
        if ($appointment->getNotes()) {
            $descParts[] = $appointment->getNotes();
        }
        $description = implode('\\n', $descParts);
        $location    = $org?->getFullAddress() ?: ($org?->getName() ?? '');

        $ics = implode("\r\n", [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//Grafira//EN',
            'CALSCALE:GREGORIAN',
            'METHOD:PUBLISH',
            'BEGIN:VEVENT',
            'UID:' . $uid,
            'DTSTAMP:' . $now,
            'DTSTART:' . $date . 'T' . $timeStr,
            'DTEND:' . $date . 'T' . $endStr,
            'SUMMARY:' . $this->escapeIcsText($summary),
            'DESCRIPTION:' . $this->escapeIcsText($description),
            'LOCATION:' . $this->escapeIcsText($location),
            'BEGIN:VALARM',
            'TRIGGER:-PT30M',
            'ACTION:DISPLAY',
            'DESCRIPTION:Reminder',
            'END:VALARM',
            'END:VEVENT',
            'END:VCALENDAR',
            '',
        ]);

        return new Response($ics, 200, [
            'Content-Type'        => 'text/calendar; charset=utf-8',
            'Content-Disposition' => 'inline; filename="appointment-' . $appointment->getId() . '.ics"',
        ]);
    }

    private function escapeIcsText(string $text): string
    {
        return str_replace([',', ';', '\\'], ['\\,', '\\;', '\\\\'], $text);
    }
}
