<?php

namespace App\Controller;

use App\Entity\Employee;
use App\Entity\User;
use App\Repository\AppointmentRepository;
use App\Repository\EmployeeRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

#[IsGranted('ROLE_EMPLOYEE')]
#[Route('/employee')]
class EmployeeProfileController extends AbstractController
{
    private function getEmployee(EmployeeRepository $repo): Employee
    {
        /** @var User $user */
        $user     = $this->getUser();
        $employee = $repo->findOneBy(['user' => $user]);

        if (!$employee) {
            throw $this->createNotFoundException('Employee profile not found.');
        }

        return $employee;
    }

    #[Route('', name: 'employee_dashboard')]
    public function dashboard(EmployeeRepository $repo, AppointmentRepository $apptRepo): Response
    {
        $employee = $this->getEmployee($repo);

        $all          = $apptRepo->findByEmployee($employee);
        $today        = new \DateTime('today');
        $upcoming     = array_filter($all, fn($a) => $a->getAppointmentDate() >= $today);
        $past         = array_filter($all, fn($a) => $a->getAppointmentDate() < $today);

        return $this->render('employee/dashboard.html.twig', [
            'employee'     => $employee,
            'organization' => $employee->getOrganization(),
            'upcoming'     => array_values($upcoming),
            'past'         => array_values($past),
        ]);
    }

    #[Route('/profile', name: 'employee_profile')]
    public function profile(
        Request $request,
        EmployeeRepository $repo,
        EntityManagerInterface $em,
        UserPasswordHasherInterface $hasher,
        TranslatorInterface $t
    ): Response {
        $employee = $this->getEmployee($repo);
        /** @var User $user */
        $user  = $this->getUser();
        $error = null;

        if ($request->isMethod('POST')) {
            $lunchStart = trim($request->request->get('lunch_start', ''));
            $lunchEnd   = trim($request->request->get('lunch_end', ''));
            $bio        = trim($request->request->get('bio', ''));
            $newPass    = $request->request->get('new_password', '');
            $confPass   = $request->request->get('confirm_password', '');

            if (($lunchStart && !$lunchEnd) || (!$lunchStart && $lunchEnd)) {
                $error = 'Please set both lunch break start and end, or leave both empty.';
            } elseif ($newPass && strlen($newPass) < 8) {
                $error = 'New password must be at least 8 characters.';
            } elseif ($newPass && $newPass !== $confPass) {
                $error = 'Passwords do not match.';
            } else {
                $employee->setLunchBreakStart($lunchStart ? new \DateTime($lunchStart) : null)
                         ->setLunchBreakEnd($lunchEnd   ? new \DateTime($lunchEnd)   : null)
                         ->setBio($bio ?: null);

                if ($newPass) {
                    $user->setPassword($hasher->hashPassword($user, $newPass));
                    $user->setMustChangePassword(false);
                }

                $avatarFile = $request->files->get('avatar');
                if ($avatarFile && $avatarFile->isValid()) {
                    $uploadsDir = $this->getParameter('kernel.project_dir') . '/public/uploads/avatars/';
                    $ext        = $avatarFile->getClientOriginalExtension() ?: 'jpg';
                    $filename   = 'emp-' . $employee->getId() . '-' . uniqid() . '.' . $ext;
                    // Delete old employee avatar if it differs from user avatar
                    if ($employee->getAvatarFilename() && $employee->getAvatarFilename() !== $user->getAvatarFilename()) {
                        @unlink($uploadsDir . $employee->getAvatarFilename());
                    }
                    $avatarFile->move($uploadsDir, $filename);
                    $employee->setAvatarFilename($filename);
                    // Keep navbar in sync
                    $user->setAvatarFilename($filename);
                }

                $em->flush();
                $this->addFlash('success', $t->trans('flash.profile_updated'));
                return $this->redirectToRoute('employee_profile');
            }
        }

        return $this->render('employee/profile.html.twig', [
            'employee'     => $employee,
            'organization' => $employee->getOrganization(),
            'error'        => $error,
        ]);
    }
}
