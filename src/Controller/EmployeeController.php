<?php

namespace App\Controller;

use App\Entity\Employee;
use App\Entity\Organization;
use App\Entity\User;
use App\Util\PhoneValidator;
use App\Repository\EmployeeRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

#[IsGranted('ROLE_BUSINESS')]
#[Route('/business/employees')]
class EmployeeController extends AbstractController
{
    #[Route('', name: 'business_employees')]
    public function index(EmployeeRepository $repo): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $org  = $user->getOrganization();

        if (!$org) {
            return $this->redirectToRoute('business_dashboard');
        }

        return $this->render('business/employees.html.twig', [
            'organization' => $org,
            'employees'    => $repo->findBy(['organization' => $org], ['name' => 'ASC']),
        ]);
    }

    #[Route('/new', name: 'business_employee_new')]
    public function new(
        Request $request,
        EntityManagerInterface $em,
        UserPasswordHasherInterface $hasher,
        UserRepository $userRepo,
        MailerInterface $mailer,
        TranslatorInterface $t
    ): Response {
        /** @var User $user */
        $user = $this->getUser();
        $org  = $user->getOrganization();

        if (!$org) {
            return $this->redirectToRoute('business_dashboard');
        }

        $error = null;

        if ($request->isMethod('POST')) {
            $name       = trim($request->request->get('name', ''));
            $role       = trim($request->request->get('role', ''));
            $phone      = trim($request->request->get('phone', ''));
            $loginEmail = trim($request->request->get('login_email', ''));
            $bio        = trim($request->request->get('bio', ''));
            $lunchStart = trim($request->request->get('lunch_start', ''));
            $lunchEnd   = trim($request->request->get('lunch_end', ''));

            $empHours = $this->parseEmployeeHours($request);

            if (!$name) {
                $error = 'Employee name is required.';
            } elseif ($phone && !PhoneValidator::isValid($phone)) {
                $error = 'Please enter a valid phone number.';
            } elseif (!$loginEmail) {
                $error = 'A login email is required so the employee can access their account.';
            } elseif (!filter_var($loginEmail, FILTER_VALIDATE_EMAIL)) {
                $error = 'Please enter a valid email address.';
            } elseif ($userRepo->findOneBy(['email' => $loginEmail])) {
                $error = 'This email is already registered.';
            } elseif (($lunchStart && !$lunchEnd) || (!$lunchStart && $lunchEnd)) {
                $error = 'Please set both lunch break start and end, or leave both empty.';
            } else {
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
                         ->setWorkingHours($empHours ?: null)
                         ->setUser($empUser);

                if ($lunchStart && $lunchEnd) {
                    $employee->setLunchBreakStart(new \DateTime($lunchStart))
                             ->setLunchBreakEnd(new \DateTime($lunchEnd));
                }

                $photos = $this->handlePortfolioUploads($request, [], $this->getParameter('kernel.project_dir'));
                $employee->setPortfolioPhotos($photos ?: null);

                $em->persist($empUser);
                $em->persist($employee);
                $em->flush();

                $email = (new Email())
                    ->from('noreply@grafira.app')
                    ->to($loginEmail)
                    ->subject('Your Grafira Employee Account')
                    ->text(sprintf(
                        "Hello %s,\n\nYour employee account has been created.\n\nEmail: %s\nTemporary password: %s\n\nYou will be asked to change your password upon first login.\n\nBest regards,\n%s",
                        $name,
                        $loginEmail,
                        $tempPassword,
                        $org->getName()
                    ));

                try {
                    $mailer->send($email);
                    $this->addFlash('success', $t->trans('flash.employee_added', ['%name%' => $name, '%email%' => $loginEmail]));
                } catch (\Throwable) {
                    $this->addFlash('warning', $t->trans('flash.employee_added_no_email', ['%name%' => $name, '%password%' => $tempPassword]));
                }

                return $this->redirectToRoute('business_employees');
            }
        }

        return $this->render('business/employee_form.html.twig', [
            'organization' => $org,
            'error'        => $error,
        ]);
    }

    #[Route('/{id}/edit', name: 'business_employee_edit')]
    public function edit(
        int $id,
        Request $request,
        EmployeeRepository $repo,
        EntityManagerInterface $em,
        UserPasswordHasherInterface $hasher,
        TranslatorInterface $t
    ): Response {
        /** @var User $user */
        $user     = $this->getUser();
        $employee = $repo->find($id);

        if (!$employee || $employee->getOrganization() !== $user->getOrganization()) {
            throw $this->createNotFoundException('Employee not found.');
        }

        $error = null;

        if ($request->isMethod('POST')) {
            $name       = trim($request->request->get('name', ''));
            $role       = trim($request->request->get('role', ''));
            $phone      = trim($request->request->get('phone', ''));
            $bio        = trim($request->request->get('bio', ''));
            $lunchStart = trim($request->request->get('lunch_start', ''));
            $lunchEnd   = trim($request->request->get('lunch_end', ''));
            $newPass    = $request->request->get('new_password', '');
            $confPass   = $request->request->get('confirm_password', '');

            $empHours = $this->parseEmployeeHours($request);

            if (!$name) {
                $error = 'Employee name is required.';
            } elseif ($phone && !PhoneValidator::isValid($phone)) {
                $error = 'Please enter a valid phone number.';
            } elseif (($lunchStart && !$lunchEnd) || (!$lunchStart && $lunchEnd)) {
                $error = 'Please set both lunch break start and end, or leave both empty.';
            } elseif ($newPass && strlen($newPass) < 8) {
                $error = 'New password must be at least 8 characters.';
            } elseif ($newPass && $newPass !== $confPass) {
                $error = 'Passwords do not match.';
            } else {
                $employee->setName($name)
                         ->setRole($role ?: null)
                         ->setPhone($phone ?: null)
                         ->setBio($bio ?: null)
                         ->setWorkingHours($empHours ?: null)
                         ->setLunchBreakStart($lunchStart ? new \DateTime($lunchStart) : null)
                         ->setLunchBreakEnd($lunchEnd   ? new \DateTime($lunchEnd)   : null);

                if ($employee->getUser()) {
                    $employee->getUser()->setName($name);
                    if ($newPass) {
                        $employee->getUser()->setPassword($hasher->hashPassword($employee->getUser(), $newPass));
                    }
                }

                $projectDir   = $this->getParameter('kernel.project_dir');
                $removePhotos = $request->request->all('remove_photos');
                $current      = $employee->getPortfolioPhotos() ?? [];
                foreach ($removePhotos as $fname) {
                    if (in_array($fname, $current, true)) {
                        @unlink($projectDir . '/public/uploads/portfolio/' . $fname);
                        $current = array_values(array_filter($current, fn($f) => $f !== $fname));
                    }
                }
                $current = $this->handlePortfolioUploads($request, $current, $projectDir);
                $employee->setPortfolioPhotos($current ?: null);

                $em->flush();

                $this->addFlash('success', $t->trans('flash.employee_updated', ['%name%' => $employee->getName()]));
                return $this->redirectToRoute('business_employees');
            }
        }

        return $this->render('business/employee_form.html.twig', [
            'organization' => $user->getOrganization(),
            'employee'     => $employee,
            'error'        => $error,
        ]);
    }

    #[Route('/{id}/create-account', name: 'business_employee_create_account', methods: ['POST'])]
    public function createAccount(
        int $id,
        Request $request,
        EmployeeRepository $repo,
        EntityManagerInterface $em,
        UserRepository $userRepo,
        UserPasswordHasherInterface $hasher,
        MailerInterface $mailer,
        TranslatorInterface $t
    ): Response {
        /** @var User $user */
        $user     = $this->getUser();
        $employee = $repo->find($id);

        if (!$employee || $employee->getOrganization() !== $user->getOrganization()) {
            throw $this->createNotFoundException('Employee not found.');
        }

        if ($employee->getUser()) {
            $this->addFlash('warning', $t->trans('flash.employee_has_account'));
            return $this->redirectToRoute('business_employee_edit', ['id' => $id]);
        }

        $loginEmail = trim($request->request->get('login_email', ''));

        if (!$loginEmail || !filter_var($loginEmail, FILTER_VALIDATE_EMAIL)) {
            $this->addFlash('danger', $t->trans('flash.employee_invalid_email'));
            return $this->redirectToRoute('business_employee_edit', ['id' => $id]);
        }

        if ($userRepo->findOneBy(['email' => $loginEmail])) {
            $this->addFlash('danger', $t->trans('flash.employee_email_taken'));
            return $this->redirectToRoute('business_employee_edit', ['id' => $id]);
        }

        $tempPassword = bin2hex(random_bytes(8));

        $empUser = new User();
        $empUser->setName($employee->getName())
                ->setEmail($loginEmail)
                ->setType(User::TYPE_EMPLOYEE)
                ->setMustChangePassword(true)
                ->setPassword($hasher->hashPassword($empUser, $tempPassword));

        $employee->setUser($empUser);

        $em->persist($empUser);
        $em->flush();

        $email = (new Email())
            ->from('noreply@grafira.app')
            ->to($loginEmail)
            ->subject('Your Grafira Employee Account')
            ->text(sprintf(
                "Hello %s,\n\nYour employee account has been created.\n\nEmail: %s\nTemporary password: %s\n\nYou will be asked to change your password upon first login.\n\nBest regards,\n%s",
                $employee->getName(),
                $loginEmail,
                $tempPassword,
                $employee->getOrganization()->getName()
            ));

        try {
            $mailer->send($email);
            $this->addFlash('success', $t->trans('flash.employee_account_created', ['%name%' => $employee->getName(), '%email%' => $loginEmail]));
        } catch (\Throwable) {
            $this->addFlash('warning', $t->trans('flash.employee_account_no_email', ['%password%' => $tempPassword]));
        }

        return $this->redirectToRoute('business_employee_edit', ['id' => $id]);
    }

    private function handlePortfolioUploads(Request $request, array $current, string $projectDir): array
    {
        $uploadDir = $projectDir . '/public/uploads/portfolio';
        $files     = $request->files->get('portfolio_photos') ?: [];
        if (!is_array($files)) {
            $files = [$files];
        }
        foreach ($files as $file) {
            if (count($current) >= 6) break;
            if (!$file || !$file->isValid()) continue;
            $ext      = $file->getClientOriginalExtension() ?: 'jpg';
            $filename = 'portfolio_' . bin2hex(random_bytes(8)) . '.' . $ext;
            $file->move($uploadDir, $filename);
            $current[] = $filename;
        }
        return $current;
    }

    private function parseEmployeeHours(Request $request): ?array
    {
        $hours = [];
        foreach (Organization::DAYS as $day) {
            $hours[$day] = [
                'enabled' => (bool) $request->request->get("emp_enabled_{$day}"),
                'open'    => $request->request->get("emp_open_{$day}", '09:00'),
                'close'   => $request->request->get("emp_close_{$day}", '18:00'),
            ];
        }
        $anyEnabled = array_filter($hours, fn($c) => $c['enabled']);
        return $anyEnabled ? $hours : null;
    }

    #[Route('/{id}/toggle', name: 'business_employee_toggle', methods: ['POST'])]
    public function toggle(int $id, EmployeeRepository $repo, EntityManagerInterface $em): Response
    {
        /** @var User $user */
        $user     = $this->getUser();
        $employee = $repo->find($id);

        if ($employee && $employee->getOrganization() === $user->getOrganization()) {
            $employee->setIsActive(!$employee->isActive());
            $em->flush();
        }

        return $this->redirectToRoute('business_employees');
    }

    #[Route('/{id}/delete', name: 'business_employee_delete', methods: ['POST'])]
    public function delete(int $id, EmployeeRepository $repo, EntityManagerInterface $em, TranslatorInterface $t): Response
    {
        /** @var User $user */
        $user     = $this->getUser();
        $employee = $repo->find($id);

        if ($employee && $employee->getOrganization() === $user->getOrganization()) {
            $em->remove($employee);
            $em->flush();
            $this->addFlash('success', $t->trans('flash.employee_removed'));
        }

        return $this->redirectToRoute('business_employees');
    }
}
