<?php

namespace App\Controller\Api;

use App\Entity\Appointment;
use App\Entity\BlockedPeriod;
use App\Entity\Employee;
use App\Entity\Organization;
use App\Entity\User;
use App\Repository\AppointmentRepository;
use App\Repository\BlockedPeriodRepository;
use App\Repository\EmployeeRepository;
use App\Repository\UserRepository;
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

#[OA\Tag(name: 'Business')]
#[IsGranted('ROLE_BUSINESS')]
#[Route('/api/v1/business')]
class BusinessApiController extends AbstractController
{
    // ─── Helpers ──────────────────────────────────────────────────────────────

    private function org(): ?Organization
    {
        /** @var User $user */
        $user = $this->getUser();
        return $user->getOrganization();
    }

    private function error(string $message, int $status = 400): JsonResponse
    {
        return new JsonResponse(['error' => $message], $status);
    }

    // ─── Appointments ─────────────────────────────────────────────────────────

    #[OA\Get(summary: 'List all appointments for the organization', responses: [new OA\Response(response: 200, description: 'Array of appointments')])]
    #[Route('/appointments', name: 'api_business_appointments', methods: ['GET'])]
    public function appointments(AppointmentRepository $repo): JsonResponse
    {
        $org = $this->org();
        if (!$org) { return $this->error('No organization.', 404); }

        return new JsonResponse([
            'appointments' => array_map($this->serializeAppt(...), $repo->findByOrganization($org)),
        ]);
    }

    #[OA\Post(summary: 'Confirm a pending appointment', parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))], responses: [new OA\Response(response: 200, description: 'Confirmed'), new OA\Response(response: 409, description: 'Not pending')])]
    #[Route('/appointments/{id}/confirm', name: 'api_business_confirm', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function confirm(int $id, AppointmentRepository $repo, EntityManagerInterface $em): JsonResponse
    {
        $org  = $this->org();
        $appt = $repo->find($id);

        if (!$appt || $appt->getOrganization() !== $org) {
            return $this->error('Appointment not found.', 404);
        }
        if ($appt->getStatus() !== Appointment::STATUS_PENDING) {
            return $this->error('Only pending appointments can be confirmed.', 409);
        }

        $appt->setStatus(Appointment::STATUS_CONFIRMED);
        $em->flush();

        return new JsonResponse(['appointment' => $this->serializeAppt($appt)]);
    }

    #[OA\Post(summary: 'Cancel any non-cancelled appointment', parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))], requestBody: new OA\RequestBody(content: new OA\JsonContent(properties: [new OA\Property(property: 'cancellation_note', type: 'string')])), responses: [new OA\Response(response: 200, description: 'Cancelled')])]
    #[Route('/appointments/{id}/cancel', name: 'api_business_cancel', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function cancel(int $id, Request $request, AppointmentRepository $repo, EntityManagerInterface $em): JsonResponse
    {
        $org  = $this->org();
        $appt = $repo->find($id);

        if (!$appt || $appt->getOrganization() !== $org) {
            return $this->error('Appointment not found.', 404);
        }
        if ($appt->getStatus() === Appointment::STATUS_CANCELLED) {
            return $this->error('Appointment is already cancelled.', 409);
        }

        $body = json_decode($request->getContent(), true) ?? [];
        $note = trim($body['cancellation_note'] ?? '');

        $appt->setStatus(Appointment::STATUS_CANCELLED)
             ->setCancellationNote($note ?: null);
        $em->flush();

        return new JsonResponse(['appointment' => $this->serializeAppt($appt)]);
    }

    #[OA\Patch(summary: 'Set appointment duration; optionally cancel overlapping pending appointments', parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))], requestBody: new OA\RequestBody(content: new OA\JsonContent(properties: [new OA\Property(property: 'minutes', type: 'integer', example: 60), new OA\Property(property: 'cancel_ids', type: 'array', items: new OA\Items(type: 'integer'))])), responses: [new OA\Response(response: 200, description: 'Updated')])]
    #[Route('/appointments/{id}/duration', name: 'api_business_duration', requirements: ['id' => '\d+'], methods: ['PATCH'])]
    public function duration(int $id, Request $request, AppointmentRepository $repo, EntityManagerInterface $em): JsonResponse
    {
        $org  = $this->org();
        $appt = $repo->find($id);

        if (!$appt || $appt->getOrganization() !== $org) {
            return $this->error('Appointment not found.', 404);
        }
        if ($appt->getStatus() === Appointment::STATUS_CANCELLED) {
            return $this->error('Cannot set duration on a cancelled appointment.', 409);
        }

        $body    = json_decode($request->getContent(), true) ?? [];
        $minutes = (int) ($body['minutes'] ?? 0);
        $appt->setDurationMinutes($minutes > 0 ? $minutes : null);

        $cancelled = [];
        foreach ($body['cancel_ids'] ?? [] as $cancelId) {
            $other = $repo->find((int) $cancelId);
            if ($other && $other->getOrganization() === $org && $other->getStatus() === Appointment::STATUS_PENDING) {
                $other->setStatus(Appointment::STATUS_CANCELLED);
                $cancelled[] = (int) $cancelId;
            }
        }

        $em->flush();

        return new JsonResponse([
            'appointment' => $this->serializeAppt($appt),
            'cancelled'   => $cancelled,
        ]);
    }

    // ─── Employees ────────────────────────────────────────────────────────────

    #[OA\Get(summary: 'List all employees in the organization', responses: [new OA\Response(response: 200, description: 'Array of employees')])]
    #[Route('/employees', name: 'api_business_employees_list', methods: ['GET'])]
    public function employeesList(EmployeeRepository $repo): JsonResponse
    {
        $org = $this->org();
        if (!$org) { return $this->error('No organization.', 404); }

        return new JsonResponse([
            'employees' => array_map($this->serializeEmployeeFull(...), $repo->findBy(['organization' => $org], ['name' => 'ASC'])),
        ]);
    }

    #[OA\Post(summary: 'Create a new employee; returns a one-time temp_password', requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['name','login_email'], properties: [new OA\Property(property: 'name', type: 'string'), new OA\Property(property: 'login_email', type: 'string'), new OA\Property(property: 'role', type: 'string'), new OA\Property(property: 'phone', type: 'string'), new OA\Property(property: 'bio', type: 'string'), new OA\Property(property: 'lunch_start', type: 'string', example: '13:00'), new OA\Property(property: 'lunch_end', type: 'string', example: '14:00'), new OA\Property(property: 'working_hours', type: 'object')])), responses: [new OA\Response(response: 201, description: 'Employee created')])]
    #[Route('/employees', name: 'api_business_employee_create', methods: ['POST'])]
    public function employeeCreate(
        Request $request,
        EmployeeRepository $repo,
        UserRepository $userRepo,
        EntityManagerInterface $em,
        UserPasswordHasherInterface $hasher
    ): JsonResponse {
        $org = $this->org();
        if (!$org) { return $this->error('No organization.', 404); }

        $body       = json_decode($request->getContent(), true) ?? [];
        $name       = trim($body['name'] ?? '');
        $role       = trim($body['role'] ?? '');
        $phone      = trim($body['phone'] ?? '');
        $bio        = trim($body['bio'] ?? '');
        $loginEmail = trim($body['login_email'] ?? '');
        $lunchStart = trim($body['lunch_start'] ?? '');
        $lunchEnd   = trim($body['lunch_end'] ?? '');

        if (!$name) { return $this->error('Employee name is required.', 422); }
        if ($phone && !PhoneValidator::isValid($phone)) { return $this->error('Invalid phone number.', 422); }
        if (!$loginEmail) { return $this->error('login_email is required.', 422); }
        if (!filter_var($loginEmail, FILTER_VALIDATE_EMAIL)) { return $this->error('Invalid login email.', 422); }
        if ($userRepo->findOneBy(['email' => $loginEmail])) { return $this->error('Email already registered.', 409); }
        if (($lunchStart && !$lunchEnd) || (!$lunchStart && $lunchEnd)) {
            return $this->error('Set both lunch_start and lunch_end or neither.', 422);
        }

        $tempPassword = bin2hex(random_bytes(8));
        $empUser = new User();
        $empUser->setName($name)
                ->setEmail($loginEmail)
                ->setType(User::TYPE_EMPLOYEE)
                ->setMustChangePassword(true)
                ->setPassword($hasher->hashPassword($empUser, $tempPassword));

        $employee = new Employee();
        $employee->setOrganization($org)
                 ->setName($name)
                 ->setRole($role ?: null)
                 ->setPhone($phone ?: null)
                 ->setBio($bio ?: null)
                 ->setWorkingHours($body['working_hours'] ?? null)
                 ->setUser($empUser);

        if ($lunchStart && $lunchEnd) {
            $employee->setLunchBreakStart(new \DateTime($lunchStart))
                     ->setLunchBreakEnd(new \DateTime($lunchEnd));
        }

        $em->persist($empUser);
        $em->persist($employee);
        $em->flush();

        return new JsonResponse([
            'employee'      => $this->serializeEmployeeFull($employee),
            'temp_password' => $tempPassword,
        ], 201);
    }

    #[OA\Get(summary: 'Get a single employee', parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))], responses: [new OA\Response(response: 200, description: 'Employee'), new OA\Response(response: 404, description: 'Not found')])]
    #[Route('/employees/{id}', name: 'api_business_employee_get', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function employeeGet(int $id, EmployeeRepository $repo): JsonResponse
    {
        $org      = $this->org();
        $employee = $repo->find($id);

        if (!$employee || $employee->getOrganization() !== $org) {
            return $this->error('Employee not found.', 404);
        }

        return new JsonResponse(['employee' => $this->serializeEmployeeFull($employee)]);
    }

    #[OA\Put(summary: 'Update an employee', parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))], requestBody: new OA\RequestBody(content: new OA\JsonContent(properties: [new OA\Property(property: 'name', type: 'string'), new OA\Property(property: 'role', type: 'string'), new OA\Property(property: 'phone', type: 'string'), new OA\Property(property: 'bio', type: 'string'), new OA\Property(property: 'lunch_start', type: 'string'), new OA\Property(property: 'lunch_end', type: 'string'), new OA\Property(property: 'working_hours', type: 'object'), new OA\Property(property: 'new_password', type: 'string'), new OA\Property(property: 'confirm_password', type: 'string')])), responses: [new OA\Response(response: 200, description: 'Updated employee')])]
    #[Route('/employees/{id}', name: 'api_business_employee_update', requirements: ['id' => '\d+'], methods: ['PUT'])]
    public function employeeUpdate(
        int $id,
        Request $request,
        EmployeeRepository $repo,
        EntityManagerInterface $em,
        UserPasswordHasherInterface $hasher
    ): JsonResponse {
        $org      = $this->org();
        $employee = $repo->find($id);

        if (!$employee || $employee->getOrganization() !== $org) {
            return $this->error('Employee not found.', 404);
        }

        $body       = json_decode($request->getContent(), true) ?? [];
        $name       = trim($body['name'] ?? $employee->getName());
        $phone      = trim($body['phone'] ?? '');
        $lunchStart = trim($body['lunch_start'] ?? '');
        $lunchEnd   = trim($body['lunch_end'] ?? '');

        if (!$name) { return $this->error('Name is required.', 422); }
        if ($phone && !PhoneValidator::isValid($phone)) { return $this->error('Invalid phone number.', 422); }
        if (($lunchStart && !$lunchEnd) || (!$lunchStart && $lunchEnd)) {
            return $this->error('Set both lunch_start and lunch_end or neither.', 422);
        }

        $newPass  = $body['new_password'] ?? '';
        $confPass = $body['confirm_password'] ?? '';
        if ($newPass) {
            if (strlen($newPass) < 8) { return $this->error('Password must be at least 8 characters.', 422); }
            if ($newPass !== $confPass) { return $this->error('Passwords do not match.', 422); }
            if ($employee->getUser()) {
                $employee->getUser()->setPassword($hasher->hashPassword($employee->getUser(), $newPass));
            }
        }

        $employee->setName($name)
                 ->setRole(trim($body['role'] ?? '') ?: $employee->getRole())
                 ->setPhone($phone ?: null)
                 ->setBio(trim($body['bio'] ?? '') ?: $employee->getBio())
                 ->setLunchBreakStart($lunchStart ? new \DateTime($lunchStart) : null)
                 ->setLunchBreakEnd($lunchEnd   ? new \DateTime($lunchEnd)   : null);

        if (isset($body['working_hours'])) {
            $employee->setWorkingHours($body['working_hours'] ?: null);
        }

        if ($employee->getUser()) {
            $employee->getUser()->setName($name);
        }

        $em->flush();

        return new JsonResponse(['employee' => $this->serializeEmployeeFull($employee)]);
    }

    #[OA\Delete(summary: 'Delete an employee', parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))], responses: [new OA\Response(response: 200, description: 'Deleted')])]
    #[Route('/employees/{id}', name: 'api_business_employee_delete', requirements: ['id' => '\d+'], methods: ['DELETE'])]
    public function employeeDelete(int $id, EmployeeRepository $repo, EntityManagerInterface $em): JsonResponse
    {
        $org      = $this->org();
        $employee = $repo->find($id);

        if (!$employee || $employee->getOrganization() !== $org) {
            return $this->error('Employee not found.', 404);
        }

        $em->remove($employee);
        $em->flush();

        return new JsonResponse(['ok' => true]);
    }

    #[OA\Post(summary: 'Toggle employee active/inactive status', parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))], responses: [new OA\Response(response: 200, description: 'Returns new active state')])]
    #[Route('/employees/{id}/toggle', name: 'api_business_employee_toggle', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function employeeToggle(int $id, EmployeeRepository $repo, EntityManagerInterface $em): JsonResponse
    {
        $org      = $this->org();
        $employee = $repo->find($id);

        if (!$employee || $employee->getOrganization() !== $org) {
            return $this->error('Employee not found.', 404);
        }

        $employee->setIsActive(!$employee->isActive());
        $em->flush();

        return new JsonResponse(['active' => $employee->isActive()]);
    }

    #[OA\Post(summary: 'Add portfolio photos (base64). Accepts data URI or raw base64.', parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(properties: [new OA\Property(property: 'photos', type: 'array', items: new OA\Items(type: 'string', example: 'data:image/jpeg;base64,/9j/4AA...'))])), responses: [new OA\Response(response: 200, description: 'Returns all photo URLs')])]
    #[Route('/employees/{id}/photos', name: 'api_business_employee_photos_add', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function employeePhotosAdd(int $id, Request $request, EmployeeRepository $repo, EntityManagerInterface $em): JsonResponse
    {
        $org      = $this->org();
        $employee = $repo->find($id);

        if (!$employee || $employee->getOrganization() !== $org) {
            return $this->error('Employee not found.', 404);
        }

        $body    = json_decode($request->getContent(), true) ?? [];
        $photos  = $body['photos'] ?? [];
        $dir     = $this->getParameter('kernel.project_dir') . '/public/uploads/portfolio';
        $current = $employee->getPortfolioPhotos() ?? [];

        foreach ($photos as $b64) {
            try {
                $current[] = Base64ImageHandler::save($b64, $dir, 'emp-' . $employee->getId());
            } catch (\InvalidArgumentException $e) {
                return $this->error($e->getMessage(), 422);
            }
        }

        $employee->setPortfolioPhotos($current ?: null);
        $em->flush();

        return new JsonResponse([
            'photos' => array_map(fn($f) => '/uploads/portfolio/' . $f, $current),
        ]);
    }

    #[OA\Delete(summary: 'Remove specific portfolio photos by filename', parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(properties: [new OA\Property(property: 'filenames', type: 'array', items: new OA\Items(type: 'string', example: 'emp-123-abc.jpg'))])), responses: [new OA\Response(response: 200, description: 'Returns remaining photo URLs')])]
    #[Route('/employees/{id}/photos', name: 'api_business_employee_photos_remove', requirements: ['id' => '\d+'], methods: ['DELETE'])]
    public function employeePhotosRemove(int $id, Request $request, EmployeeRepository $repo, EntityManagerInterface $em): JsonResponse
    {
        $org      = $this->org();
        $employee = $repo->find($id);

        if (!$employee || $employee->getOrganization() !== $org) {
            return $this->error('Employee not found.', 404);
        }

        $body    = json_decode($request->getContent(), true) ?? [];
        $remove  = $body['filenames'] ?? [];
        $dir     = $this->getParameter('kernel.project_dir') . '/public/uploads/portfolio';
        $current = $employee->getPortfolioPhotos() ?? [];

        foreach ($remove as $filename) {
            $current = array_filter($current, fn($f) => $f !== $filename);
            @unlink($dir . '/' . $filename);
        }

        $current = array_values($current);
        $employee->setPortfolioPhotos($current ?: null);
        $em->flush();

        return new JsonResponse([
            'photos' => array_map(fn($f) => '/uploads/portfolio/' . $f, $current),
        ]);
    }

    // ─── Settings / Info ──────────────────────────────────────────────────────

    #[OA\Get(summary: 'Get organization info and settings', responses: [new OA\Response(response: 200, description: 'Organization')])]
    #[Route('/settings/info', name: 'api_business_settings_info_get', methods: ['GET'])]
    public function settingsInfoGet(): JsonResponse
    {
        $org = $this->org();
        if (!$org) { return $this->error('No organization.', 404); }

        return new JsonResponse(['organization' => $this->serializeOrg($org)]);
    }

    #[OA\Put(summary: 'Update organization info. Logo must be base64-encoded.', requestBody: new OA\RequestBody(content: new OA\JsonContent(properties: [new OA\Property(property: 'phone', type: 'string'), new OA\Property(property: 'email', type: 'string'), new OA\Property(property: 'description', type: 'string'), new OA\Property(property: 'address', type: 'string'), new OA\Property(property: 'city', type: 'string'), new OA\Property(property: 'country', type: 'string'), new OA\Property(property: 'zip_code', type: 'string'), new OA\Property(property: 'latitude', type: 'number'), new OA\Property(property: 'longitude', type: 'number'), new OA\Property(property: 'logo_base64', type: 'string', example: 'data:image/png;base64,iVBO...')])), responses: [new OA\Response(response: 200, description: 'Updated organization')])]
    #[Route('/settings/info', name: 'api_business_settings_info_update', methods: ['PUT'])]
    public function settingsInfoUpdate(Request $request, EntityManagerInterface $em): JsonResponse
    {
        $org = $this->org();
        if (!$org) { return $this->error('No organization.', 404); }

        $body  = json_decode($request->getContent(), true) ?? [];
        $phone = trim($body['phone'] ?? '');

        if ($phone && !PhoneValidator::isValid($phone)) {
            return $this->error('Invalid phone number.', 422);
        }

        $org->setPhone($phone ?: null)
            ->setEmail(trim($body['email'] ?? '') ?: $org->getEmail())
            ->setDescription(trim($body['description'] ?? '') ?: $org->getDescription())
            ->setAddress(trim($body['address'] ?? '') ?: $org->getAddress())
            ->setCity(trim($body['city'] ?? '') ?: $org->getCity())
            ->setCountry(trim($body['country'] ?? '') ?: $org->getCountry())
            ->setZipCode(trim($body['zip_code'] ?? '') ?: $org->getZipCode());

        if (array_key_exists('latitude', $body)) {
            $org->setLatitude($body['latitude'] !== null ? (float) $body['latitude'] : null);
        }
        if (array_key_exists('longitude', $body)) {
            $org->setLongitude($body['longitude'] !== null ? (float) $body['longitude'] : null);
        }

        if (!empty($body['logo_base64'])) {
            try {
                $dir      = $this->getParameter('kernel.project_dir') . '/public/uploads/logos';
                $filename = Base64ImageHandler::save($body['logo_base64'], $dir, 'org-' . $org->getId());
                if ($org->getLogoFilename()) {
                    @unlink($dir . '/' . $org->getLogoFilename());
                }
                $org->setLogoFilename($filename);
            } catch (\InvalidArgumentException $e) {
                return $this->error($e->getMessage(), 422);
            }
        }

        $em->flush();

        return new JsonResponse(['organization' => $this->serializeOrg($org)]);
    }

    // ─── Settings / Working Hours ─────────────────────────────────────────────

    #[OA\Get(summary: 'Get organization working hours and time step', responses: [new OA\Response(response: 200, description: 'Working hours object')])]
    #[Route('/settings/hours', name: 'api_business_settings_hours_get', methods: ['GET'])]
    public function settingsHoursGet(): JsonResponse
    {
        $org = $this->org();
        if (!$org) { return $this->error('No organization.', 404); }

        return new JsonResponse([
            'working_hours' => $org->getWorkingHours(),
            'time_step'     => $org->getTimeStep(),
        ]);
    }

    #[OA\Put(summary: 'Update working hours and/or time step (15, 30, or 60 minutes)', requestBody: new OA\RequestBody(content: new OA\JsonContent(properties: [new OA\Property(property: 'working_hours', type: 'object', example: ['monday' => ['start' => '09:00', 'end' => '18:00']]), new OA\Property(property: 'time_step', type: 'integer', enum: [15, 30, 60])])), responses: [new OA\Response(response: 200, description: 'Updated hours')])]
    #[Route('/settings/hours', name: 'api_business_settings_hours_update', methods: ['PUT'])]
    public function settingsHoursUpdate(Request $request, EntityManagerInterface $em): JsonResponse
    {
        $org = $this->org();
        if (!$org) { return $this->error('No organization.', 404); }

        $body = json_decode($request->getContent(), true) ?? [];

        if (array_key_exists('working_hours', $body)) {
            $org->setWorkingHours($body['working_hours'] ?: null);
        }
        if (isset($body['time_step']) && in_array((int) $body['time_step'], [15, 30, 60], true)) {
            $org->setTimeStep((int) $body['time_step']);
        }

        $em->flush();

        return new JsonResponse([
            'working_hours' => $org->getWorkingHours(),
            'time_step'     => $org->getTimeStep(),
        ]);
    }

    // ─── Blocked Periods ──────────────────────────────────────────────────────

    #[OA\Get(summary: 'List all blocked periods', responses: [new OA\Response(response: 200, description: 'Array of blocked periods')])]
    #[Route('/blocked-periods', name: 'api_business_blocked_list', methods: ['GET'])]
    public function blockedList(BlockedPeriodRepository $repo): JsonResponse
    {
        $org = $this->org();
        if (!$org) { return $this->error('No organization.', 404); }

        return new JsonResponse([
            'blocked_periods' => array_map($this->serializeBlock(...), $repo->findByOrganizationOrdered($org)),
        ]);
    }

    #[OA\Post(summary: 'Create a blocked period', requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['date','start_time','end_time'], properties: [new OA\Property(property: 'date', type: 'string', example: '2026-06-01'), new OA\Property(property: 'start_time', type: 'string', example: '12:00'), new OA\Property(property: 'end_time', type: 'string', example: '13:00'), new OA\Property(property: 'label', type: 'string'), new OA\Property(property: 'employee_id', type: 'integer')])), responses: [new OA\Response(response: 201, description: 'Created')])]
    #[Route('/blocked-periods', name: 'api_business_blocked_create', methods: ['POST'])]
    public function blockedCreate(Request $request, EmployeeRepository $empRepo, EntityManagerInterface $em): JsonResponse
    {
        $org = $this->org();
        if (!$org) { return $this->error('No organization.', 404); }

        $body      = json_decode($request->getContent(), true) ?? [];
        $date      = $body['date'] ?? '';
        $startTime = $body['start_time'] ?? '';
        $endTime   = $body['end_time'] ?? '';
        $label     = trim($body['label'] ?? '');
        $empId     = (int) ($body['employee_id'] ?? 0);

        if (!$date || !$startTime || !$endTime) {
            return $this->error('date, start_time and end_time are required.', 422);
        }
        if (new \DateTime($startTime) >= new \DateTime($endTime)) {
            return $this->error('end_time must be after start_time.', 422);
        }

        $employee = $empId ? $empRepo->find($empId) : null;
        if ($employee && $employee->getOrganization() !== $org) {
            return $this->error('Employee not found in this organization.', 404);
        }

        $block = new BlockedPeriod();
        $block->setOrganization($org)
              ->setEmployee($employee)
              ->setDate(new \DateTime($date))
              ->setStartTime(new \DateTime($startTime))
              ->setEndTime(new \DateTime($endTime))
              ->setLabel($label ?: null);

        $em->persist($block);
        $em->flush();

        return new JsonResponse(['blocked_period' => $this->serializeBlock($block)], 201);
    }

    #[OA\Delete(summary: 'Delete a blocked period', parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))], responses: [new OA\Response(response: 200, description: 'Deleted')])]
    #[Route('/blocked-periods/{id}', name: 'api_business_blocked_delete', requirements: ['id' => '\d+'], methods: ['DELETE'])]
    public function blockedDelete(int $id, BlockedPeriodRepository $repo, EntityManagerInterface $em): JsonResponse
    {
        $org   = $this->org();
        $block = $repo->find($id);

        if (!$block || $block->getOrganization() !== $org) {
            return $this->error('Blocked period not found.', 404);
        }

        $em->remove($block);
        $em->flush();

        return new JsonResponse(['ok' => true]);
    }

    // ─── Profile ──────────────────────────────────────────────────────────────

    #[OA\Get(summary: 'Get the authenticated business user profile', responses: [new OA\Response(response: 200, description: 'User profile')])]
    #[Route('/profile', name: 'api_business_profile_get', methods: ['GET'])]
    public function profileGet(): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        return new JsonResponse(['user' => $this->serializeUser($user)]);
    }

    #[OA\Put(summary: 'Update business user profile. Avatar must be base64-encoded.', requestBody: new OA\RequestBody(content: new OA\JsonContent(properties: [new OA\Property(property: 'name', type: 'string'), new OA\Property(property: 'phone', type: 'string'), new OA\Property(property: 'avatar_base64', type: 'string'), new OA\Property(property: 'new_password', type: 'string'), new OA\Property(property: 'confirm_password', type: 'string')])), responses: [new OA\Response(response: 200, description: 'Updated profile')])]
    #[Route('/profile', name: 'api_business_profile_update', methods: ['PUT'])]
    public function profileUpdate(Request $request, EntityManagerInterface $em, UserPasswordHasherInterface $hasher): JsonResponse
    {
        /** @var User $user */
        $user  = $this->getUser();
        $body  = json_decode($request->getContent(), true) ?? [];
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

        $user->setName($name)->setPhone($phone ?: null);

        if (!empty($body['avatar_base64'])) {
            try {
                $dir      = $this->getParameter('kernel.project_dir') . '/public/uploads/avatars';
                $filename = Base64ImageHandler::save($body['avatar_base64'], $dir, 'user-' . $user->getId());
                if ($user->getAvatarFilename()) {
                    @unlink($dir . '/' . $user->getAvatarFilename());
                }
                $user->setAvatarFilename($filename);
            } catch (\InvalidArgumentException $e) {
                return $this->error($e->getMessage(), 422);
            }
        }

        $em->flush();

        return new JsonResponse(['user' => $this->serializeUser($user)]);
    }

    // ─── Serializers ──────────────────────────────────────────────────────────

    private function serializeAppt(Appointment $a): array
    {
        return [
            'id'               => $a->getId(),
            'booker_name'      => $a->getBookerName(),
            'booker_phone'     => $a->getBookerPhone(),
            'guest'            => $a->isGuestBooking(),
            'date'             => $a->getAppointmentDate()->format('Y-m-d'),
            'time'             => $a->getAppointmentTime()->format('H:i'),
            'status'           => $a->getStatus(),
            'employee_name'    => $a->getEmployee()?->getName(),
            'employee_role'    => $a->getEmployee()?->getRole(),
            'employee_id'      => $a->getEmployee()?->getId(),
            'messaging_apps'   => $a->getClient()?->getMessagingApps() ?? [],
            'notes'            => $a->getNotes(),
            'cancel_note'      => $a->getCancellationNote(),
            'duration_minutes' => $a->getDurationMinutes(),
        ];
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

    private function serializeEmployeeFull(Employee $emp): array
    {
        return [
            'id'            => $emp->getId(),
            'name'          => $emp->getName(),
            'role'          => $emp->getRole(),
            'phone'         => $emp->getPhone(),
            'bio'           => $emp->getBio(),
            'is_active'     => $emp->isActive(),
            'login_email'   => $emp->getUser()?->getEmail(),
            'working_hours' => $emp->getWorkingHours(),
            'lunch_start'   => $emp->getLunchBreakStart()?->format('H:i'),
            'lunch_end'     => $emp->getLunchBreakEnd()?->format('H:i'),
            'photos'        => array_map(fn($f) => '/uploads/portfolio/' . $f, $emp->getPortfolioPhotos() ?? []),
        ];
    }

    private function serializeUser(User $user): array
    {
        return [
            'id'         => $user->getId(),
            'name'       => $user->getName(),
            'email'      => $user->getEmail(),
            'phone'      => $user->getPhone(),
            'avatar_url' => $user->getAvatarFilename() ? '/uploads/avatars/' . $user->getAvatarFilename() : null,
        ];
    }

    private function serializeBlock(BlockedPeriod $b): array
    {
        return [
            'id'          => $b->getId(),
            'date'        => $b->getDate()->format('Y-m-d'),
            'start_time'  => $b->getStartTime()->format('H:i'),
            'end_time'    => $b->getEndTime()->format('H:i'),
            'label'       => $b->getLabel(),
            'employee_id' => $b->getEmployee()?->getId(),
        ];
    }
}
