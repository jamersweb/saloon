<?php

namespace App\Services;

use App\Jobs\SendWhatsAppDeliveryJob;
use App\Models\Appointment;
use App\Models\CommunicationLog;
use App\Models\StaffProfile;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use InvalidArgumentException;

class StaffAppointmentNotificationService
{
    public function __construct(
        private readonly WhatsAppService $whatsAppService,
    ) {
    }

    /**
     * @param  iterable<Appointment>  $appointments
     */
    public function notifyAssignedStaff(iterable $appointments, string $reason = 'assigned'): void
    {
        $appointmentCollection = new EloquentCollection(
            Collection::make($appointments)
                ->filter(fn ($appointment) => $appointment instanceof Appointment)
                ->all()
        );

        $appointmentCollection
            ->loadMissing(['service:id,name', 'staffProfile.user:id,name'])
            ->groupBy(fn (Appointment $appointment) => (int) ($appointment->staff_profile_id ?? 0))
            ->each(function (Collection $staffAppointments, int $staffProfileId) use ($reason): void {
                if ($staffProfileId <= 0) {
                    return;
                }

                $staffProfile = StaffProfile::query()
                    ->with('user:id,name')
                    ->find($staffProfileId);

                if (! $staffProfile || ! $staffProfile->phone) {
                    return;
                }

                $primaryAppointment = $staffAppointments->sortBy('scheduled_start')->first();
                if (! $primaryAppointment instanceof Appointment) {
                    return;
                }

                $message = $this->buildMessage($staffProfile, $staffAppointments, $reason);
                $this->queueWhatsApp((string) $staffProfile->phone, $message, 'staff_appointment:' . $primaryAppointment->id);
            });
    }

    private function buildMessage(StaffProfile $staffProfile, Collection $appointments, string $reason): string
    {
        $staffName = $staffProfile->user?->name ?: 'Staff';
        $reasonLabel = $reason === 'updated' ? 'updated' : 'new';

        $lines = $appointments
            ->sortBy('scheduled_start')
            ->map(function (Appointment $appointment): string {
                $start = $appointment->scheduled_start?->timezone(config('app.timezone'))->format('M j, Y g:i A') ?? 'N/A';
                $end = $appointment->scheduled_end?->timezone(config('app.timezone'))->format('g:i A') ?? 'N/A';

                return sprintf(
                    '- %s for %s on %s to %s',
                    $appointment->service?->name ?: 'Service',
                    $appointment->customer_name ?: 'Client',
                    $start,
                    $end
                );
            })
            ->implode("\n");

        return trim("Hello {$staffName}, you have a {$reasonLabel} appointment.\n{$lines}");
    }

    private function queueWhatsApp(string $recipient, string $message, string $context): void
    {
        try {
            $normalizedRecipient = $this->whatsAppService->normalizeRecipientForTransport($recipient);
        } catch (InvalidArgumentException) {
            return;
        }

        $payload = [
            'message_type' => 'text',
            'recipient' => $normalizedRecipient,
            'message' => $message,
            'template_name' => null,
            'language_code' => null,
            'components' => [],
        ];

        $log = CommunicationLog::create([
            'customer_id' => null,
            'channel' => 'whatsapp',
            'context' => $context,
            'recipient' => $normalizedRecipient,
            'message' => $message,
            'status' => 'queued',
            'provider' => 'whatsapp',
            'provider_status' => 'queued',
            'message_type' => 'text',
            'queued_at' => now(),
            'provider_payload' => $payload,
        ]);

        SendWhatsAppDeliveryJob::dispatch($log->id, $payload);
    }
}
