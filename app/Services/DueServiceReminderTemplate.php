<?php

namespace App\Services;

use App\Models\CustomerDueService;
use App\Models\FinanceSetting;
use App\Models\WhatsAppMessageTemplate;
use InvalidArgumentException;

class DueServiceReminderTemplate
{
    public function options(CustomerDueService $due): array
    {
        $due->loadMissing(['service', 'customer']);
        $settings = FinanceSetting::current();
        $preset = config('due_service_reminders.'.$due->service?->due_reminder_key);
        $preset = $due->service?->due_reminder_key ? $preset : null;
        $name = $preset['name'] ?? $settings->whatsapp_due_service_template_name;
        $language = $preset['language'] ?? ($settings->whatsapp_default_language_code ?: config('services.whatsapp.default_language_code', 'en_US'));
        $template = WhatsAppMessageTemplate::query()->where('name', $name)->where('language', $language)->first();
        if (! $template || strtoupper((string) $template->status) !== 'APPROVED') {
            throw new InvalidArgumentException('The due-service template '.($name ?: '(not configured)').' must be approved and synced before sending.');
        }

        $body = collect($template->components ?? [])->first(fn ($component) => strtolower($component['type'] ?? '') === 'body');
        preg_match_all('/{{\s*(\d+)\s*}}/', $body['text'] ?? '', $matches);
        $numbers = array_unique($matches[1]);
        sort($numbers, SORT_NUMERIC);
        $values = [1 => $due->customer?->name ?: 'Customer', 2 => $due->service?->name ?: 'service', 3 => $due->due_date?->toDateString()];
        $parameters = [];
        foreach ($numbers as $number) {
            if (! isset($values[$number])) {
                throw new InvalidArgumentException('Due-service reminders support customer name {{1}}, service {{2}}, and due date {{3}} only.');
            }
            $parameters[] = ['type' => 'text', 'text' => $values[$number]];
        }
        $components = $parameters ? [['type' => 'body', 'parameters' => $parameters]] : [];
        app(WhatsAppTemplateValidator::class)->validate($name, $language, $components);

        return ['async' => true, 'message_type' => 'template', 'template_name' => $name, 'language_code' => $language, 'components' => $components];
    }

    public function message(CustomerDueService $due): string
    {
        $due->loadMissing(['service', 'customer']);
        $preset = $due->service?->due_reminder_key ? config('due_service_reminders.'.$due->service->due_reminder_key) : null;
        return $preset
            ? str_replace('{{1}}', $due->customer?->name ?: 'Customer', $preset['body'])
            : sprintf('Hi %s, your %s service is due on %s. Reply to book your next appointment.', $due->customer?->name ?: 'Customer', $due->service?->name ?: 'service', $due->due_date?->toDateString());
    }
}
