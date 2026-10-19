<?php

namespace App\Console\Commands;

use App\Models\CustomerDueService;
use App\Models\FinanceSetting;
use App\Models\WhatsAppMessageTemplate;
use App\Services\CommunicationDeliveryService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SendDueServiceReminders extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:send-due-service-reminders {--channel=whatsapp} {--policy=single} {--fallback=email} {--limit=200}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Dispatch due-service reminders and log communication history';

    /**
     * Execute the console command.
     */
    public function handle(CommunicationDeliveryService $communicationDeliveryService): int
    {
        $channel = $this->option('channel');
        $policy = $this->option('policy');
        $fallback = $this->option('fallback');
        $limit = (int) $this->option('limit');

        if (! in_array($channel, ['email', 'whatsapp'], true)) {
            $this->error('Invalid channel. Use email or whatsapp; SMS is disabled.');

            return self::FAILURE;
        }

        if (! in_array($policy, ['single', 'fallback'], true)) {
            $this->error('Invalid policy. Use single or fallback.');

            return self::FAILURE;
        }

        if (! in_array($fallback, ['email', 'whatsapp'], true)) {
            $this->error('Invalid fallback channel. Use email or whatsapp; SMS is disabled.');

            return self::FAILURE;
        }

        $dueServices = CustomerDueService::query()
            ->with(['customer:id,name,phone,email', 'service:id,name,due_reminder_key'])
            ->where('status', 'pending')
            ->whereDate('due_date', '<=', now()->toDateString())
            ->whereNull('reminder_sent_at')
            ->whereNotExists(function ($query) {
                $query->selectRaw('1')->from('communication_logs')
                    ->where('channel', 'whatsapp')
                    ->where(function ($q) {
                        $q->whereRaw('context = '.(DB::getDriverName() === 'sqlite' ? "'due_service_reminder:' || customer_due_services.id" : "CONCAT('due_service_reminder:', customer_due_services.id)"))
                            ->orWhereRaw('context = '.(DB::getDriverName() === 'sqlite' ? "'due_service_reminder_auto:' || customer_due_services.id" : "CONCAT('due_service_reminder_auto:', customer_due_services.id)"));
                    });
            })
            ->orderBy('due_date')
            ->limit(max(1, $limit))
            ->get();

        if ($dueServices->isEmpty()) {
            $this->info('No due-service reminders to dispatch.');

            return self::SUCCESS;
        }

        $sent = 0;

        foreach ($dueServices as $dueService) {
            $attemptChannels = [$channel];
            if ($policy === 'fallback' && $fallback !== $channel) {
                $attemptChannels[] = $fallback;
            }

            $sentForCustomer = false;

            foreach ($attemptChannels as $attemptChannel) {
                $recipient = $this->resolveRecipient($dueService, $attemptChannel);
                $deliveryOptions = $this->deliveryOptions($attemptChannel, $dueService);

                if (($deliveryOptions['configuration_error'] ?? null) !== null) {
                    $this->warn((string) $deliveryOptions['configuration_error']);

                    continue;
                }

                $log = $communicationDeliveryService->deliver(
                    $dueService->customer,
                    $attemptChannel,
                    $recipient,
                    app(\App\Services\DueServiceReminderTemplate::class)->message($dueService),
                    'due_service_reminder_auto:'.$dueService->id,
                    $deliveryOptions,
                );

                if (! in_array($log->status, ['queued', 'sent'], true)) {
                    continue;
                }

                $sent++;
                $sentForCustomer = true;
                break;
            }

            if (! $sentForCustomer) {
                continue;
            }
        }

        $this->info("Due-service reminders dispatched: {$sent}");

        return self::SUCCESS;
    }

    private function resolveRecipient(CustomerDueService $dueService, string $channel): ?string
    {
        return $channel === 'email'
            ? $dueService->customer?->email
            : $dueService->customer?->phone;
    }

    /**
     * @return array<string, mixed>
     */
    private function deliveryOptions(string $channel, CustomerDueService $dueService): array
    {
        if ($channel !== 'whatsapp') {
            return [];
        }

        try {
            return app(\App\Services\DueServiceReminderTemplate::class)->options($dueService);
        } catch (\InvalidArgumentException $exception) {
            return ['configuration_error' => $exception->getMessage()];
        }
    }
}