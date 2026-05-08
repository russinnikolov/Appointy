<?php

namespace App\Controller\Api;

use App\Entity\Appointment;
use App\Entity\Organization;
use App\Repository\AppointmentRepository;
use App\Repository\EmployeeRepository;
use App\Repository\OrganizationRepository;
use App\Util\PhoneValidator;
use Doctrine\ORM\EntityManagerInterface;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[OA\Tag(name: 'Public')]
#[Route('/api/v1')]
class PublicApiController extends AbstractController
{
    #[OA\Get(
        summary: 'List all organizations (optionally search by name)',
        security: [],
        parameters: [new OA\Parameter(name: 'q', in: 'query', required: false, schema: new OA\Schema(type: 'string'))],
        responses: [new OA\Response(response: 200, description: 'Array of organizations')]
    )]
    #[Route('/organizations', name: 'api_organizations_list', methods: ['GET'])]
    public function list(Request $request, OrganizationRepository $repo): JsonResponse
    {
        $search = trim($request->query->get('q', ''));
        $orgs   = $search ? $repo->search($search) : $repo->findBy([], ['name' => 'ASC']);

        return new JsonResponse(['organizations' => array_map($this->serializeOrg(...), $orgs)]);
    }

    #[OA\Get(
        summary: 'Get a single organization with its active employees',
        security: [],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(response: 200, description: 'Organization and employees'),
            new OA\Response(response: 404, description: 'Not found'),
        ]
    )]
    #[Route('/organizations/{id}', name: 'api_organization_show', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function show(int $id, OrganizationRepository $orgRepo, EmployeeRepository $empRepo): JsonResponse
    {
        $org = $orgRepo->find($id);
        if (!$org) {
            return new JsonResponse(['error' => 'Organization not found.'], 404);
        }

        return new JsonResponse([
            'organization' => $this->serializeOrg($org),
            'employees'    => array_map($this->serializeEmployee(...), $empRepo->findActiveByOrganization($org)),
        ]);
    }

    #[OA\Post(
        summary: 'Create a guest booking (no account required)',
        security: [],
        parameters: [
            new OA\Parameter(name: 'orgId',      in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'employeeId', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['guest_name', 'guest_phone', 'date', 'time'],
            properties: [
                new OA\Property(property: 'guest_name',  type: 'string', example: 'Ivan Petrov'),
                new OA\Property(property: 'guest_phone', type: 'string', example: '+359888123456'),
                new OA\Property(property: 'date',        type: 'string', example: '2026-06-01'),
                new OA\Property(property: 'time',        type: 'string', example: '10:00'),
                new OA\Property(property: 'notes',       type: 'string', example: 'Please be on time'),
            ]
        )),
        responses: [
            new OA\Response(response: 201, description: 'Appointment created'),
            new OA\Response(response: 409, description: 'Time slot already taken'),
            new OA\Response(response: 422, description: 'Validation error'),
        ]
    )]
    #[Route('/organizations/{orgId}/book/{employeeId}', name: 'api_guest_book', requirements: ['orgId' => '\d+', 'employeeId' => '\d+'], methods: ['POST'])]
    public function guestBook(
        int $orgId,
        int $employeeId,
        Request $request,
        OrganizationRepository $orgRepo,
        EmployeeRepository $empRepo,
        AppointmentRepository $apptRepo,
        EntityManagerInterface $em
    ): JsonResponse {
        $org      = $orgRepo->find($orgId);
        $employee = $empRepo->find($employeeId);

        if (!$org || !$employee || $employee->getOrganization() !== $org) {
            return new JsonResponse(['error' => 'Organization or employee not found.'], 404);
        }

        $body       = json_decode($request->getContent(), true) ?? [];
        $guestName  = trim($body['guest_name'] ?? '');
        $guestPhone = trim($body['guest_phone'] ?? '');
        $date       = $body['date'] ?? '';
        $time       = $body['time'] ?? '';
        $notes      = trim($body['notes'] ?? '');

        if (!$guestName || !$guestPhone) {
            return new JsonResponse(['error' => 'guest_name and guest_phone are required.'], 422);
        }
        if (!PhoneValidator::isValid($guestPhone)) {
            return new JsonResponse(['error' => 'Invalid phone number.'], 422);
        }
        if (!$date || !$time) {
            return new JsonResponse(['error' => 'date and time are required.'], 422);
        }

        $step = $org->getTimeStep();
        $allowedMinutes = [];
        for ($m = 0; $m < 60; $m += $step) {
            $allowedMinutes[] = str_pad((string) $m, 2, '0', STR_PAD_LEFT);
        }
        if (!in_array(substr($time, 3, 2), $allowedMinutes, true)) {
            return new JsonResponse(['error' => 'Invalid time slot.'], 422);
        }
        if (new \DateTime($date) < new \DateTime('today')) {
            return new JsonResponse(['error' => 'Date cannot be in the past.'], 422);
        }
        if ($apptRepo->findConflict($employee, new \DateTime($date), new \DateTime($time), null, $step)) {
            return new JsonResponse(['error' => $employee->getName() . ' is already booked at that time.'], 409);
        }

        $appt = new Appointment();
        $appt->setOrganization($org)
             ->setEmployee($employee)
             ->setGuestName($guestName)
             ->setGuestPhone($guestPhone)
             ->setAppointmentDate(new \DateTime($date))
             ->setAppointmentTime(new \DateTime($time))
             ->setNotes($notes ?: null);

        $em->persist($appt);
        $em->flush();

        return new JsonResponse(['appointment' => $this->serializeAppt($appt)], 201);
    }

    private function serializeOrg(Organization $org): array
    {
        return [
            'id'          => $org->getId(),
            'name'        => $org->getName(),
            'category'    => $org->getCategory(),
            'phone'       => $org->getPhone(),
            'email'       => $org->getEmail(),
            'address'     => $org->getAddress(),
            'city'        => $org->getCity(),
            'country'     => $org->getCountry(),
            'zip_code'    => $org->getZipCode(),
            'description' => $org->getDescription(),
            'latitude'    => $org->getLatitude(),
            'longitude'   => $org->getLongitude(),
            'time_step'   => $org->getTimeStep(),
            'logo_url'    => $org->getLogoFilename() ? '/uploads/logos/' . $org->getLogoFilename() : null,
        ];
    }

    private function serializeEmployee(\App\Entity\Employee $emp): array
    {
        return [
            'id'     => $emp->getId(),
            'name'   => $emp->getName(),
            'role'   => $emp->getRole(),
            'phone'  => $emp->getPhone(),
            'bio'    => $emp->getBio(),
            'photos' => array_map(fn($f) => '/uploads/portfolio/' . $f, $emp->getPortfolioPhotos() ?? []),
        ];
    }

    private function serializeAppt(Appointment $a): array
    {
        return [
            'id'       => $a->getId(),
            'date'     => $a->getAppointmentDate()->format('Y-m-d'),
            'time'     => $a->getAppointmentTime()->format('H:i'),
            'status'   => $a->getStatus(),
            'notes'    => $a->getNotes(),
            'employee' => $a->getEmployee()?->getName(),
        ];
    }
}
