<?php

namespace App\Console\Commands;

use App\Models\CommunicationLog;
use App\Models\CustomerDueService;
use Illuminate\Console\Command;

class SendDueServiceReminders extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:send-due-service-reminders {--channel=sms} {--policy=single} {--fallback=email} {--limit=200}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Dispatch due-service reminders and log communication history';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $channel = $this->option('channel');
        $policy = $this->option('policy');
        $fallback = $this->option('fallback');
        $limit = (int) $this->option('limit');

        if (! in_array($channel, ['sms', 'email', 'whatsapp'], true)) {
            $this->error('Invalid channel. Use sms, email, or whatsapp.');
            return self::FAILURE;
        }

        if (! in_array($policy, ['single', 'fallback'], true)) {
            $this->error('Invalid policy. Use single or fallback.');
            return self::FAILURE;
        }

        if (! in_array($fallback, ['sms', 'email', 'whatsapp'], true)) {
            $this->error('Invalid fallback channel. Use sms, email, or whatsapp.');
            return self::FAILURE;
        }

        $dueServices = CustomerDueService::query()
            ->with(['customer:id,name,phone,email', 'service:id,name'])
            ->where('status', 'pending')
            ->whereDate('due_date', '<=', now()->toDateString())
            ->whereNull('reminder_sent_at')
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
                if (! $recipient) {
                    CommunicationLog::create([
                        'customer_id' => $dueService->customer_id,
                        'channel' => $attemptChannel,
                        'context' => 'due_service_reminder_auto',
                        'recipient' => null,
                        'message' => null,
                        'status' => 'failed',
                        'sent_at' => now(),
                    ]);
                    continue;
                }

                CommunicationLog::create([
                    'customer_id' => $dueService->customer_id,
                    'channel' => $attemptChannel,
                    'context' => 'due_service_reminder_auto',
                    'recipient' => $recipient,
                    'message' => sprintf(
                        'Hi %s, your %s service is due on %s. Reply to book your next appointment.',
                        $dueService->customer?->name ?? 'Customer',
                        $dueService->service?->name ?? 'service',
                        $dueService->due_date?->toDateString()
                    ),
                    'status' => 'sent',
                    'sent_at' => now(),
                ]);

                $dueService->update(['reminder_sent_at' => now()]);
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
}
