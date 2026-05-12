<?php

namespace App\Command;

use App\Repository\OrganizationRepository;
use App\Repository\ServiceRepository;
use App\Service\TranslationApiService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:backfill-translations',
    description: 'Translate all Service and Organization names/descriptions that have not been translated yet.',
)]
class BackfillTranslationsCommand extends Command
{
    public function __construct(
        private readonly TranslationApiService  $translator,
        private readonly ServiceRepository      $serviceRepo,
        private readonly OrganizationRepository $orgRepo,
        private readonly EntityManagerInterface $em,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('force', null, InputOption::VALUE_NONE, 'Re-translate even rows that already have translations');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io    = new SymfonyStyle($input, $output);
        $force = (bool) $input->getOption('force');

        // ── Services ─────────────────────────────────────────────────────────
        $services = $this->serviceRepo->findAll();
        $io->section(sprintf('Services (%d total)', count($services)));

        $done = 0;
        foreach ($services as $service) {
            if (!$force && $service->getNameEn() !== null && $service->getNameBg() !== null) {
                continue; // already translated
            }
            $this->translator->translateService($service, $service->getSourceLocale());
            $done++;
            $io->writeln(sprintf('  [service #%d] %s', $service->getId(), $service->getName()));
        }
        $this->em->flush();
        $io->success(sprintf('%d service(s) translated.', $done));

        // ── Organizations ────────────────────────────────────────────────────
        $orgs = $this->orgRepo->findAll();
        $io->section(sprintf('Organizations (%d total)', count($orgs)));

        $done = 0;
        foreach ($orgs as $org) {
            if (!$force && $org->getNameEn() !== null && $org->getNameBg() !== null) {
                continue; // already translated
            }
            $this->translator->translateOrg($org, $org->getSourceLocale());
            $done++;
            $io->writeln(sprintf('  [org #%d] %s', $org->getId(), $org->getName()));
        }
        $this->em->flush();
        $io->success(sprintf('%d organization(s) translated.', $done));

        return Command::SUCCESS;
    }
}
