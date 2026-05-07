<?php

namespace App\Controller;

use App\Entity\Employee;
use App\Entity\User;
use App\Repository\EmployeeRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

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
    public function new(Request $request, EntityManagerInterface $em): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $org  = $user->getOrganization();

        if (!$org) {
            return $this->redirectToRoute('business_dashboard');
        }

        $error = null;

        if ($request->isMethod('POST')) {
            $name  = trim($request->request->get('name', ''));
            $role  = trim($request->request->get('role', ''));
            $phone = trim($request->request->get('phone', ''));
            $email = trim($request->request->get('email', ''));
            $bio   = trim($request->request->get('bio', ''));

            if (!$name) {
                $error = 'Employee name is required.';
            } else {
                $employee = new Employee();
                $employee->setOrganization($org)
                         ->setName($name)
                         ->setRole($role ?: null)
                         ->setPhone($phone ?: null)
                         ->setEmail($email ?: null)
                         ->setBio($bio ?: null);

                $em->persist($employee);
                $em->flush();

                $this->addFlash('success', $name . ' has been added.');
                return $this->redirectToRoute('business_employees');
            }
        }

        return $this->render('business/employee_form.html.twig', [
            'organization' => $org,
            'error'        => $error,
        ]);
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
    public function delete(int $id, EmployeeRepository $repo, EntityManagerInterface $em): Response
    {
        /** @var User $user */
        $user     = $this->getUser();
        $employee = $repo->find($id);

        if ($employee && $employee->getOrganization() === $user->getOrganization()) {
            $em->remove($employee);
            $em->flush();
            $this->addFlash('success', 'Employee removed.');
        }

        return $this->redirectToRoute('business_employees');
    }
}
