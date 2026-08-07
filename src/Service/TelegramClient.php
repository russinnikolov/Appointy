<?php

namespace App\Service;

use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Thin wrapper around the Telegram Bot API (https://core.telegram.org/bots/api).
 * All calls are best-effort: callers decide whether a failure should be swallowed.
 */
class TelegramClient
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly string $botToken,
    ) {
    }

    /**
     * @param array<int, array<int, array{text: string, callback_data: string}>>|null $inlineKeyboard rows of buttons
     * @return array{chat_id: int|string, message_id: int}
     */
    public function sendMessage(string $chatId, string $text, ?array $inlineKeyboard = null): array
    {
        $payload = [
            'chat_id'    => $chatId,
            'text'       => $text,
            'parse_mode' => 'HTML',
        ];

        if ($inlineKeyboard !== null) {
            $payload['reply_markup'] = json_encode(['inline_keyboard' => $inlineKeyboard]);
        }

        $result = $this->call('sendMessage', $payload);

        return ['chat_id' => $result['chat']['id'], 'message_id' => $result['message_id']];
    }

    public function editMessageText(string $chatId, string $messageId, string $text): void
    {
        $this->call('editMessageText', [
            'chat_id'    => $chatId,
            'message_id' => $messageId,
            'text'       => $text,
            'parse_mode' => 'HTML',
        ]);
    }

    public function answerCallbackQuery(string $callbackQueryId, ?string $text = null, bool $showAlert = false): void
    {
        $payload = ['callback_query_id' => $callbackQueryId];
        if ($text !== null) {
            $payload['text']       = $text;
            $payload['show_alert'] = $showAlert;
        }

        $this->call('answerCallbackQuery', $payload);
    }

    public function setWebhook(string $url, string $secretToken): void
    {
        $this->call('setWebhook', ['url' => $url, 'secret_token' => $secretToken]);
    }

    /** @return array<string, mixed> the API response's "result" field */
    private function call(string $method, array $payload): array
    {
        $response = $this->httpClient->request(
            'POST',
            "https://api.telegram.org/bot{$this->botToken}/{$method}",
            ['json' => $payload, 'timeout' => 10]
        );

        $data = $response->toArray(false);
        if (!($data['ok'] ?? false)) {
            throw new \RuntimeException('Telegram API error: ' . ($data['description'] ?? 'unknown'));
        }

        return $data['result'] ?? [];
    }
}
