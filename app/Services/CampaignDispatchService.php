<?php

namespace App\Services;

use App\Models\Appointment;
use App\Models\Campaign;
use App\Models\Customer;
use App\Models\CustomerDueService;
use Illuminate\Database\Eloquent\Builder;

class CampaignDispatchService
{
    public function __construct(
        private readonly CommunicationDeliveryService $communicationDeliveryService,
    ) {
    }

    public function dispatch(Campaign $campaign): array
    {
        $campaign->loadMissing('template');

        $customers = $this->resolveAudience($campaign)->get();
        $sent = 0;
        $failed = 0;

        foreach ($customers as $customer) {
            $recipient = $campaign->channel === 'email' ? $customer->email : $customer->phone;

            $message = str_replace('{name}', $customer->name, $campaign->template?->content ?? '');

            $log = $this->communicationDeliveryService->deliver(
                $customer,
                $campaign->channel,
                $recipient,
                $message,
                'campaign:' . $campaign->id,
            );

            if ($log->status === 'sent') {
                $sent++;
                continue;
            }

            $failed++;
        }

        $campaign->update([
            'status' => 'sent',
            'sent_count' => $campaign->sent_count + $sent,
            'failed_count' => $campaign->failed_count + $failed,
            'last_run_at' => now(),
        ]);

        return ['sent' => $sent, 'failed' => $failed];
    }

    private function resolveAudience(Campaign $campaign): Builder
    {
        $customerCreatedAtExpr = "'1970-01-01 00:00:00'";

        return match ($campaign->audience_type) {
            'tag' => Customer::query()
                ->where('is_active', true)
                ->whereHas('tags', fn ($q) => $q->where('customer_tags.id', $campaign->customer_tag_id)),
            'due_service' => Customer::query()
                ->where('is_active', true)
                ->whereIn('id', CustomerDueService::query()
                    ->where('status', 'pending')
                    ->whereDate('due_date', '<=', now()->toDateString())
                    ->pluck('customer_id')),
            'inactivity_days' => Customer::query()
                ->where('is_active', true)
                ->leftJoin('appointments', function ($join): void {
                    $join->on('customers.id', '=', 'appointments.customer_id')
                        ->where('appointments.status', Appointment::STATUS_COMPLETED);
                })
                ->select('customers.*')
                ->groupBy('customers.id')
                ->havingRaw("COALESCE(MAX(appointments.scheduled_start), {$customerCreatedAtExpr}) <= ?", [now()->subDays((int) ($campaign->inactivity_days ?? 30))->toDateTimeString()]),
            default => Customer::query()->where('is_active', true),
        };
    }
}
