<?php

namespace App\Command;

use App\Entity\Invoice;
use App\Entity\Subscription;
use App\Repository\AppointmentRepository;
use App\Repository\EmployeeRepository;
use App\Repository\InvoiceRepository;
use App\Repository\SubscriptionRepository;
use App\Service\PlanCatalog;
use App\Service\StripeService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:generate-monthly-invoices',
    description: 'Generates invoices for the previous calendar month for every active/trialing organization subscription. Intended to run on the 1st of each month.',
)]
class GenerateMonthlyInvoicesCommand extends Command
{
    public function __construct(
        private readonly SubscriptionRepository $subscriptionRepo,
        private readonly InvoiceRepository $invoiceRepo,
        private readonly AppointmentRepository $appointmentRepo,
        private readonly EmployeeRepository $employeeRepo,
        private readonly StripeService $stripe,
        private readonly EntityManagerInterface $em,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io  = new SymfonyStyle($input, $output);
        $now = new \DateTimeImmutable('first day of this month midnight');

        $periodStart = $now->modify('first day of last month');
        $periodEnd   = $now->modify('last day of last month');
        $dueDate     = $now->modify('+9 days'); // 10th of the current month

        $subscriptions = $this->subscriptionRepo->findAll();
        $io->section(sprintf('Billing period %s to %s (%d subscriptions)', $periodStart->format('Y-m-d'), $periodEnd->format('Y-m-d'), count($subscriptions)));

        $created = 0;
        foreach ($subscriptions as $subscription) {
            if ($subscription->isTrialing()) {
                continue;
            }
            if ($this->invoiceRepo->findOneForPeriod($subscription, $periodStart, $periodEnd)) {
                continue; // idempotent — already billed this period
            }

            $org  = $subscription->getOrganization();
            $plan = $subscription->getPlan();

            $reservationCount = $this->appointmentRepo->countConfirmedForOrganizationBetween($org, $periodStart, $periodEnd);
            $employeeCount    = $this->employeeRepo->count(['organization' => $org]);
            $amountDueCents   = PlanCatalog::amountDueCents($plan, $reservationCount, $employeeCount);

            $invoice = new Invoice();
            $invoice->setSubscription($subscription)
                ->setOrganization($org)
                ->setPlan($plan)
                ->setPeriodStart($periodStart)
                ->setPeriodEnd($periodEnd)
                ->setReservationCount($reservationCount)
                ->setEmployeeCountAtBilling($employeeCount)
                ->setAmountDueCents($amountDueCents)
                ->setDueDate($dueDate);

            if ($amountDueCents === 0) {
                $invoice->setStatus(Invoice::STATUS_PAID)->setPaidAt(new \DateTimeImmutable());
            } else {
                $invoice->setStatus(Invoice::STATUS_PENDING);
            }

            $this->em->persist($invoice);
            $created++;

            $io->writeln(sprintf('  [org #%d] %s: %d cents (plan %s)', $org->getId(), $org->getName(), $amountDueCents, $plan->value));

            if ($amountDueCents > 0) {
                try {
                    $this->stripe->chargeInvoice($invoice);
                } catch (\Throwable $e) {
                    $io->warning(sprintf('Stripe charge failed for org #%d: %s', $org->getId(), $e->getMessage()));
                }
            }
        }

        $this->em->flush();
        $io->success(sprintf('%d invoice(s) generated.', $created));

        return Command::SUCCESS;
    }
}
