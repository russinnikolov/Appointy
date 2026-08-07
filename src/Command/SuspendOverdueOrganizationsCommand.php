<?php

namespace App\Command;

use App\Entity\Invoice;
use App\Entity\Subscription;
use App\Repository\InvoiceRepository;
use App\Repository\SubscriptionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;

#[AsCommand(
    name: 'app:suspend-overdue-organizations',
    description: 'Marks unpaid invoices past their due date as overdue and suspends booking for those organizations. Also expires trials with no active paid subscription. Intended to run daily.',
)]
class SuspendOverdueOrganizationsCommand extends Command
{
    public function __construct(
        private readonly InvoiceRepository $invoiceRepo,
        private readonly SubscriptionRepository $subscriptionRepo,
        private readonly MailerInterface $mailer,
        private readonly EntityManagerInterface $em,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io  = new SymfonyStyle($input, $output);
        $now = new \DateTimeImmutable('today');

        // ── Overdue invoices ─────────────────────────────────────────────────
        $overdueInvoices = $this->invoiceRepo->findPendingPastDue($now);
        $io->section(sprintf('Overdue invoices (%d)', count($overdueInvoices)));

        foreach ($overdueInvoices as $invoice) {
            $invoice->setStatus(Invoice::STATUS_OVERDUE);
            $subscription = $invoice->getSubscription();
            $subscription->setStatus(Subscription::STATUS_PAST_DUE);

            $this->notifyOrg($subscription, 'Your Grafira account has been suspended', 'Your invoice is overdue. Booking has been paused until payment is received.');
            $io->writeln(sprintf('  [org #%d] suspended (overdue invoice #%d)', $subscription->getOrganization()->getId(), $invoice->getId()));
        }

        // ── Expired trials with no active paid subscription ────────────────
        $expiredTrials = $this->subscriptionRepo->findExpiredTrials($now);
        $io->section(sprintf('Expired trials (%d)', count($expiredTrials)));

        foreach ($expiredTrials as $subscription) {
            if ($subscription->getStripeSubscriptionId()) {
                continue; // already converted to a real Stripe subscription; webhook manages status from here
            }
            $subscription->setStatus(Subscription::STATUS_PAST_DUE);
            $this->notifyOrg($subscription, 'Your Grafira trial has ended', 'Please choose a subscription plan to keep receiving reservations.');
            $io->writeln(sprintf('  [org #%d] trial expired, no plan selected', $subscription->getOrganization()->getId()));
        }

        $this->em->flush();
        $io->success('Done.');

        return Command::SUCCESS;
    }

    private function notifyOrg(Subscription $subscription, string $subject, string $body): void
    {
        $org = $subscription->getOrganization();
        if (!$org->getEmail()) {
            return;
        }

        $email = (new Email())
            ->from('noreply@grafira.app')
            ->to($org->getEmail())
            ->subject($subject)
            ->text($body);

        try {
            $this->mailer->send($email);
        } catch (\Throwable) {
            // best-effort notification; do not block the suspension itself
        }
    }
}
