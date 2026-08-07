<?php

namespace App\Controller\Api;

use App\Entity\Appointment;
use App\Entity\User;
use App\Repository\AppointmentRepository;
use App\Repository\EmployeeRepository;
use App\Repository\OrganizationRepository;
use App\Service\SubscriptionService;
use App\Service\NotificationService;
use App\Util\Base64ImageHandler;
use App\Util\PhoneValidator;
use Doctrine\ORM\EntityManagerInterface;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[OA\Tag(name: 'Client')]
#[IsGranted('ROLE_CLIENT')]
#[Route('/api/v1/client')]
class ClientApiController extends AbstractController
{
    #[OA\Get(
        summary: 'List all appointments for the authenticated client',
        responses: [new OA\Response(response: 200, description: 'Array of appointments')]
    )]
    #[Route('/appointments', name: 'api_client_appointments', methods: ['GET'])]
    public function appointments(AppointmentRepository $repo): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        return new JsonResponse(['appointments' => array_map($this->serializeAppt(...), $repo->findByClient($user))]);
    }

    #[OA\Post(
        summary: 'Book an appointment at an organization',
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['org_id', 'date', 'time'],
            properties: [
                new OA\Property(property: 'org_id',      type: 'integer', example: 1),
                new OA\Property(property: 'date',        type: 'string',  example: '2026-06-01'),
                new OA\Property(property: 'time',        type: 'string',  example: '10:00'),
                new OA\Property(property: 'employee_id', type: 'integer', example: 3),
                new OA\Property(property: 'notes',       type: 'string',  example: 'Allergic to certain products'),
            ]
        )),
        responses: [
            new OA\Response(response: 201, description: 'Appointment created'),
            new OA\Response(response: 409, description: 'Time slot conflict'),
            new OA\Response(response: 422, description: 'Validation error'),
        ]
    )]
    #[Route('/appointments', name: 'api_client_book', methods: ['POST'])]
    public function book(
        Request $request,
        OrganizationRepository $orgRepo,
        EmployeeRepository $empRepo,
        AppointmentRepository $apptRepo,
        EntityManagerInterface $em,
        SubscriptionService $subscriptionService
        NotificationService $notifications
    ): JsonResponse {
        /** @var User $user */
        $user = $this->getUser();
        $body = json_decode($request->getContent(), true) ?? [];

        $orgId      = (int) ($body['org_id'] ?? 0);
        $date       = $body['date'] ?? '';
        $time       = $body['time'] ?? '';
        $notes      = trim($body['notes'] ?? '');
        $employeeId = (int) ($body['employee_id'] ?? 0);

        $org = $orgRepo->find($orgId);
        if (!$org) { return $this->error('Organization not found.', 404); }

        if (!$subscriptionService->canAcceptReservations($org)) {
            return $this->error('This business is not currently accepting reservations.', 403);
        }

        $employee = null;
        if ($employeeId) {
            $employee = $empRepo->find($employeeId);
            if (!$employee || $employee->getOrganization() !== $org) {
                return $this->error('Employee not found in this organization.', 404);
            }
        }

        if (!$date || !$time) { return $this->error('date and time are required.', 422); }

        $step = $org->getTimeStep();
        $allowedMinutes = [];
        for ($m = 0; $m < 60; $m += $step) {
            $allowedMinutes[] = str_pad((string) $m, 2, '0', STR_PAD_LEFT);
        }
        if (!in_array(substr($time, 3, 2), $allowedMinutes, true)) { return $this->error('Invalid time slot.', 422); }
        if (new \DateTime($date) < new \DateTime('today')) { return $this->error('Date cannot be in the past.', 422); }
        if ($employee && $apptRepo->findConflict($employee, new \DateTime($date), new \DateTime($time), null, $step)) {
            return $this->error($employee->getName() . ' is already booked at that time.', 409);
        }

        $appt = new Appointment();
        $appt->setClient($user)
             ->setOrganization($org)
             ->setEmployee($employee)
             ->setAppointmentDate(new \DateTime($date))
             ->setAppointmentTime(new \DateTime($time))
             ->setNotes($notes ?: null);

        $em->persist($appt);
        $em->flush();
        $notifications->notifyNewReservation($appt);

        return new JsonResponse(['appointment' => $this->serializeAppt($appt)], 201);
    }

    #[OA\Post(
        summary: 'Cancel a pending appointment',
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        requestBody: new OA\RequestBody(content: new OA\JsonContent(properties: [
            new OA\Property(property: 'cancellation_note', type: 'string', example: 'Change of plans'),
        ])),
        responses: [
            new OA\Response(response: 200, description: 'Appointment cancelled'),
            new OA\Response(response: 404, description: 'Appointment not found'),
            new OA\Response(response: 409, description: 'Not cancellable'),
        ]
    )]
    #[Route('/appointments/{id}/cancel', name: 'api_client_cancel', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function cancel(int $id, Request $request, AppointmentRepository $repo, EntityManagerInterface $em): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        $appt = $repo->find($id);

        if (!$appt || $appt->getClient() !== $user) { return $this->error('Appointment not found.', 404); }
        if ($appt->getStatus() !== Appointment::STATUS_PENDING) {
            return $this->error('Only pending appointments can be cancelled.', 409);
        }

        $body = json_decode($request->getContent(), true) ?? [];
        $appt->setStatus(Appointment::STATUS_CANCELLED)
             ->setCancellationNote(trim($body['cancellation_note'] ?? '') ?: null);
        $em->flush();

        return new JsonResponse(['appointment' => $this->serializeAppt($appt)]);
    }

    #[OA\Get(
        summary: 'Get the authenticated client profile',
        responses: [new OA\Response(response: 200, description: 'User profile')]
    )]
    #[Route('/profile', name: 'api_client_profile_get', methods: ['GET'])]
    public function profileGet(): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        return new JsonResponse(['user' => $this->serializeUser($user)]);
    }

    #[OA\Put(
        summary: 'Update client profile. Avatar must be base64-encoded (data URI or raw).',
        requestBody: new OA\RequestBody(content: new OA\JsonContent(properties: [
            new OA\Property(property: 'name',             type: 'string', example: 'Ivan Ivanov'),
            new OA\Property(property: 'phone',            type: 'string', example: '+359888123456'),
            new OA\Property(property: 'address',          type: 'string', example: 'ul. Vitosha 1'),
            new OA\Property(property: 'city',             type: 'string', example: 'Sofia'),
            new OA\Property(property: 'country',          type: 'string', example: 'Bulgaria'),
            new OA\Property(property: 'messaging_apps',   type: 'array',  items: new OA\Items(type: 'string')),
            new OA\Property(property: 'avatar_base64',    type: 'string', example: 'data:image/jpeg;base64,/9j/4AA...'),
            new OA\Property(property: 'new_password',     type: 'string', example: 'newpass123'),
            new OA\Property(property: 'confirm_password', type: 'string', example: 'newpass123'),
        ])),
        responses: [
            new OA\Response(response: 200, description: 'Updated profile'),
            new OA\Response(response: 422, description: 'Validation error'),
        ]
    )]
    #[Route('/profile', name: 'api_client_profile_update', methods: ['PUT'])]
    public function profileUpdate(
        Request $request,
        EntityManagerInterface $em,
        UserPasswordHasherInterface $hasher
    ): JsonResponse {
        /** @var User $user */
        $user = $this->getUser();
        $body = json_decode($request->getContent(), true) ?? [];

        $name  = trim($body['name'] ?? $user->getName());
        $phone = trim($body['phone'] ?? '');

        if (!$name) { return $this->error('Name is required.', 422); }
        if ($phone && !PhoneValidator::isValid($phone)) { return $this->error('Invalid phone number.', 422); }

        $newPass  = $body['new_password'] ?? '';
        $confPass = $body['confirm_password'] ?? '';
        if ($newPass) {
            if (strlen($newPass) < 8) { return $this->error('Password must be at least 8 characters.', 422); }
            if ($newPass !== $confPass) { return $this->error('Passwords do not match.', 422); }
            $user->setPassword($hasher->hashPassword($user, $newPass));
        }

        $apps = isset($body['messaging_apps'])
            ? array_values(array_intersect($body['messaging_apps'], User::MESSAGING_APPS))
            : $user->getMessagingApps();

        $user->setName($name)
             ->setPhone($phone ?: null)
             ->setAddress(trim($body['address'] ?? '') ?: $user->getAddress())
             ->setCity(trim($body['city'] ?? '') ?: $user->getCity())
             ->setCountry(trim($body['country'] ?? '') ?: $user->getCountry())
             ->setMessagingApps($apps);

        if (!empty($body['avatar_base64'])) {
            try {
                $dir      = $this->getParameter('kernel.project_dir') . '/public/uploads/avatars';
                $filename = Base64ImageHandler::save($body['avatar_base64'], $dir, 'user-' . $user->getId());
                if ($user->getAvatarFilename()) { @unlink($dir . '/' . $user->getAvatarFilename()); }
                $user->setAvatarFilename($filename);
            } catch (\InvalidArgumentException $e) {
                return $this->error($e->getMessage(), 422);
            }
        }

        $em->flush();

        return new JsonResponse(['user' => $this->serializeUser($user)]);
    }

    private function serializeAppt(Appointment $a): array
    {
        return [
            'id'           => $a->getId(),
            'org_name'     => $a->getOrganization()->getName(),
            'org_id'       => $a->getOrganization()->getId(),
            'date'         => $a->getAppointmentDate()->format('Y-m-d'),
            'time'         => $a->getAppointmentTime()->format('H:i'),
            'status'       => $a->getStatus(),
            'employee'     => $a->getEmployee()?->getName(),
            'emp_role'     => $a->getEmployee()?->getRole(),
            'emp_phone'    => $a->getEmployee()?->getPhone(),
            'org_phone'    => $a->getOrganization()->getPhone(),
            'notes'        => $a->getNotes(),
            'cancel_note'  => $a->getCancellationNote(),
        ];
    }

    private function serializeUser(User $user): array
    {
        return [
            'id'             => $user->getId(),
            'name'           => $user->getName(),
            'email'          => $user->getEmail(),
            'phone'          => $user->getPhone(),
            'address'        => $user->getAddress(),
            'city'           => $user->getCity(),
            'country'        => $user->getCountry(),
            'messaging_apps' => $user->getMessagingApps(),
            'avatar_url'     => $user->getAvatarFilename() ? '/uploads/avatars/' . $user->getAvatarFilename() : null,
        ];
    }

    private function error(string $message, int $status): JsonResponse
    {
        return new JsonResponse(['error' => $message], $status);
    }
}
