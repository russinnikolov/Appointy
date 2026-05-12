<?php

namespace App\Controller;

use App\Entity\Appointment;
use App\Entity\User;
use App\Repository\AppointmentRepository;
use App\Repository\EmployeeRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_BUSINESS')]
#[Route('/business/reports')]
class BusinessReportController extends AbstractController
{
    #[Route('/custom', name: 'business_report_custom', methods: ['GET'])]
    public function custom(Request $request, AppointmentRepository $repo, EmployeeRepository $empRepo): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $org  = $user->getOrganization();
        if (!$org) {
            return $this->redirectToRoute('business_dashboard');
        }

        $dateFrom   = $request->query->get('date_from', '');
        $dateTo     = $request->query->get('date_to', '');
        $status     = $request->query->get('status', '');
        $employeeId = (int) $request->query->get('employee_id', 0);

        if (!$dateFrom || !$dateTo) {
            return new Response('Start date and end date are required.', 400);
        }

        try {
            $from = new \DateTime($dateFrom . ' 00:00:00');
            $to   = new \DateTime($dateTo . ' 23:59:59');
        } catch (\Exception) {
            return new Response('Invalid date format.', 400);
        }

        if ($from > $to) {
            return new Response('Start date must be before end date.', 400);
        }

        $employee = $employeeId ? $empRepo->find($employeeId) : null;
        if ($employee && $employee->getOrganization() !== $org) {
            $employee = null;
        }

        $appointments = $repo->findForReport(
            $org,
            $from,
            $to,
            $status ?: null,
            $employee
        );

        $filename = sprintf(
            'report-%s-%s%s%s.csv',
            $dateFrom,
            $dateTo,
            $status ? '-' . $status : '',
            $employee ? '-' . preg_replace('/\s+/', '_', $employee->getName()) : ''
        );

        return $this->buildCsvResponse($appointments, $filename, $org->getName(), [
            'Period'   => $dateFrom . ' — ' . $dateTo,
            'Status'   => $status ?: 'All',
            'Employee' => $employee ? $employee->getName() : 'All',
        ], $org->getTimeStep());
    }

    #[Route('/monthly', name: 'business_report_monthly', methods: ['GET'])]
    public function monthly(Request $request, AppointmentRepository $repo): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $org  = $user->getOrganization();
        if (!$org) {
            return $this->redirectToRoute('business_dashboard');
        }

        $month = $request->query->get('month', ''); // format: YYYY-MM

        if (!$month || !preg_match('/^\d{4}-\d{2}$/', $month)) {
            return new Response('A valid month (YYYY-MM) is required.', 400);
        }

        $from = new \DateTime($month . '-01 00:00:00');
        $to   = (clone $from)->modify('last day of this month')->setTime(23, 59, 59);

        $appointments = $repo->findForReport($org, $from, $to);

        $filename = sprintf('monthly-report-%s-%s.csv', $month, preg_replace('/\s+/', '_', $org->getName()));

        return $this->buildCsvResponse($appointments, $filename, $org->getName(), [
            'Month' => $from->format('F Y'),
        ], $org->getTimeStep());
    }

    private function buildCsvResponse(array $appointments, string $filename, string $orgName, array $meta, int $timeStep = 30): StreamedResponse
    {
        $response = new StreamedResponse(function () use ($appointments, $orgName, $meta, $timeStep) {
            $out = fopen('php://output', 'w');

            // UTF-8 BOM so Excel opens it correctly
            fputs($out, "\xEF\xBB\xBF");

            // Meta header block
            fputcsv($out, ['Organization', $orgName]);
            fputcsv($out, ['Generated', (new \DateTime())->format('Y-m-d H:i:s')]);
            foreach ($meta as $key => $value) {
                fputcsv($out, [$key, $value]);
            }
            fputcsv($out, ['Total appointments', count($appointments)]);
            fputcsv($out, []);

            // Column headers
            fputcsv($out, ['#', 'Date', 'Time', 'Status', 'Client / Guest', 'Phone', 'Employee', 'Employee Role', 'Duration (min)', 'Notes', 'Cancellation Note']);

            foreach ($appointments as $i => $a) {
                fputcsv($out, [
                    $i + 1,
                    $a->getAppointmentDate()->format('Y-m-d'),
                    $a->getAppointmentTime()->format('H:i'),
                    ucfirst($a->getStatus()),
                    $a->getBookerName(),
                    $a->getBookerPhone() ?? '',
                    $a->getEmployee()?->getName() ?? '',
                    $a->getEmployee()?->getRole() ?? '',
                    $a->getDurationMinutes() ?? $timeStep,
                    $a->getNotes() ?? '',
                    $a->getCancellationNote() ?? '',
                ]);
            }

            fclose($out);
        });

        $response->headers->set('Content-Type', 'text/csv; charset=UTF-8');
        $response->headers->set('Content-Disposition', 'attachment; filename="' . $filename . '"');

        return $response;
    }
}
