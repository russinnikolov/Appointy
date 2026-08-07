<?php

namespace App\Command;

use App\Service\TelegramClient;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:telegram-set-webhook',
    description: 'Registers the app\'s /webhooks/telegram endpoint with Telegram. Run once per environment after deploying (or whenever TELEGRAM_WEBHOOK_SECRET changes).',
)]
class SetTelegramWebhookCommand extends Command
{
    public function __construct(
        private readonly TelegramClient $telegram,
        private readonly string $webhookSecret,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('url', InputArgument::REQUIRED, 'Public HTTPS URL of /webhooks/telegram, e.g. https://app.example.com/webhooks/telegram');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $this->telegram->setWebhook($input->getArgument('url'), $this->webhookSecret);
        $io->success('Telegram webhook registered.');

        return Command::SUCCESS;
    }
}
