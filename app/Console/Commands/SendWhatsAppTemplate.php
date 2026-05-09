<?php

namespace App\Console\Commands;

use App\Services\WhatsAppService;
use Illuminate\Console\Command;
use InvalidArgumentException;

class SendWhatsAppTemplate extends Command
{
    protected $signature = 'app:send-whatsapp-template
        {recipient : Destination phone number in international format}
        {template : Approved WhatsApp template name}
        {--language=en_US : Template language code}';

    protected $description = 'Send a WhatsApp template message through the configured provider';

    public function handle(WhatsAppService $whatsAppService): int
    {
        try {
            $result = $whatsAppService->sendTemplate(
                (string) $this->argument('recipient'),
                (string) $this->argument('template'),
                (string) $this->option('language'),
            );
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        if (! $result['successful']) {
            $this->error($result['error_message'] ?? 'WhatsApp template send failed.');

            return self::FAILURE;
        }

        $this->info('WhatsApp template sent successfully.');
        $this->line('Provider: ' . (string) $result['provider']);
        $this->line('Message ID: ' . (string) ($result['provider_message_id'] ?? 'n/a'));
        $this->line('Recipient: ' . (string) $result['recipient']);

        return self::SUCCESS;
    }
}
