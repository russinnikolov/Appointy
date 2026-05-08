<?php

namespace App\Controller\Api;

use App\Entity\Employee;
use App\Entity\User;
use App\Repository\AppointmentRepository;
use App\Repository\EmployeeRepository;
use App\Util\Base64ImageHandler;
use Doctrine\ORM\EntityManagerInterface;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[OA\Tag(name: 'Employee')]
#[IsGranted('ROLE_EMPLOYEE')]
#[Route('/api/v1/employee')]
class EmployeeApiController extends AbstractController
{
    private function getEmployee(EmployeeRepository $repo): ?Employee
    {
        /** @var User $user */
        $user = $this->getUser();
        return $repo->findOneBy(['user' => $user]);
    }

    #[OA\Get(summary: 'Get upcoming and past appointments for the employee', responses: [new OA\Response(response: 200, description: 'upcoming and past arrays')])]
    #[Route('/appointments', name: 'api_employee_appointments', methods: ['GET'])]
    public function appointments(EmployeeRepository $repo, AppointmentRepository $apptRepo): JsonResponse
    {
        $employee = $this->getEmployee($repo);
        if (!$employee) { return new JsonResponse(['error' => 'Employee not found.'], 404); }

        $today    = new \DateTime('today');
        $all      = $apptRepo->findByEmployee($employee);
        $upcoming = array_values(array_filter($all, fn($a) => $a->getAppointmentDate() >= $today));
        $past     = array_values(array_filter($all, fn($a) => $a->getAppointmentDate() < $today));

        return new JsonResponse([
            'upcoming' => array_map($this->serializeAppt(...), $upcoming),
            'past'     => array_map($this->serializeAppt(...), $past),
        ]);
    }

    #[OA\Get(summary: 'Get the employee profile', responses: [new OA\Response(response: 200, description: 'Employee object')])]
    #[Route('/profile', name: 'api_employee_profile_get', methods: ['GET'])]
    public function profileGet(EmployeeRepository $repo): JsonResponse
    {
        $employee = $this->getEmployee($repo);
        if (!$employee) { return new JsonResponse(['error' => 'Employee not found.'], 404); }

        return new JsonResponse(['employee' => $this->serializeEmployee($employee)]);
    }

    #[OA\Put(summary: 'Update employee profile. Avatar must be base64-encoded.', requestBody: new OA\RequestBody(content: new OA\JsonContent(properties: [new OA\Property(property: 'bio', type: 'string'), new OA\Property(property: 'lunch_start', type: 'string', example: '13:00'), new OA\Property(property: 'lunch_end', type: 'string', example: '14:00'), new OA\Property(property: 'avatar_base64', type: 'string'), new OA\Property(property: 'new_password', type: 'string'), new OA\Property(property: 'confirm_password', type: 'string')])), responses: [new OA\Response(response: 200, description: 'Updated employee')])]
    #[Route('/profile', name: 'api_employee_profile_update', methods: ['PUT'])]
    public function profileUpdate(
        Request $request,
        EmployeeRepository $repo,
        EntityManagerInterface $em,
        UserPasswordHasherInterface $hasher
    ): JsonResponse {
        $employee = $this->getEmployee($repo);
        if (!$employee) { return new JsonResponse(['error' => 'Employee not found.'], 404); }

        /** @var User $user */
        $user = $this->getUser();
        $body = json_decode($request->getContent(), true) ?? [];

        $lunchStart = trim($body['lunch_start'] ?? '');
        $lunchEnd   = trim($body['lunch_end'] ?? '');

        if (($lunchStart && !$lunchEnd) || (!$lunchStart && $lunchEnd)) {
            return new JsonResponse(['error' => 'Set both lunch_start and lunch_end or neither.'], 422);
        }

        $newPass  = $body['new_password'] ?? '';
        $confPass = $body['confirm_password'] ?? '';
        if ($newPass) {
            if (strlen($newPass) < 8) { return new JsonResponse(['error' => 'Password must be at least 8 characters.'], 422); }
            if ($newPass !== $confPass) { return new JsonResponse(['error' => 'Passwords do not match.'], 422); }
            $user->setPassword($hasher->hashPassword($user, $newPass));
            $user->setMustChangePassword(false);
        }

        $employee->setBio(trim($body['bio'] ?? '') ?: $employee->getBio())
                 ->setLunchBreakStart($lunchStart ? new \DateTime($lunchStart) : null)
                 ->setLunchBreakEnd($lunchEnd   ? new \DateTime($lunchEnd)   : null);

        if (!empty($body['avatar_base64'])) {
            try {
                $dir      = $this->getParameter('kernel.project_dir') . '/public/uploads/avatars';
                $filename = Base64ImageHandler::save($body['avatar_base64'], $dir, 'user-' . $user->getId());
                if ($user->getAvatarFilename()) {
                    @unlink($dir . '/' . $user->getAvatarFilename());
                }
                $user->setAvatarFilename($filename);
            } catch (\InvalidArgumentException $e) {
                return new JsonResponse(['error' => $e->getMessage()], 422);
            }
        }

        $em->flush();

        return new JsonResponse(['employee' => $this->serializeEmployee($employee)]);
    }

    private function serializeAppt(\App\Entity\Appointment $a): array
    {
        return [
            'id'           => $a->getId(),
            'booker_name'  => $a->getBookerName(),
            'booker_phone' => $a->getBookerPhone(),
            'date'         => $a->getAppointmentDate()->format('Y-m-d'),
            'time'         => $a->getAppointmentTime()->format('H:i'),
            'status'       => $a->getStatus(),
            'notes'        => $a->getNotes(),
            'cancel_note'  => $a->getCancellationNote(),
        ];
    }

    private function serializeEmployee(Employee $emp): array
    {
        /** @var User $user */
        $user = $this->getUser();

        return [
            'id'            => $emp->getId(),
            'name'          => $emp->getName(),
            'role'          => $emp->getRole(),
            'phone'         => $emp->getPhone(),
            'bio'           => $emp->getBio(),
            'lunch_start'   => $emp->getLunchBreakStart()?->format('H:i'),
            'lunch_end'     => $emp->getLunchBreakEnd()?->format('H:i'),
            'working_hours' => $emp->getWorkingHours(),
            'photos'        => array_map(fn($f) => '/uploads/portfolio/' . $f, $emp->getPortfolioPhotos() ?? []),
            'avatar_url'    => $user->getAvatarFilename() ? '/uploads/avatars/' . $user->getAvatarFilename() : null,
            'organization'  => $emp->getOrganization()->getName(),
        ];
    }
}
