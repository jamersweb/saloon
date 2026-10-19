<?php

namespace Database\Seeders;

use App\Models\WhatsAppMessageTemplate;
use Illuminate\Database\Seeder;

class DueServiceReminderTemplatesSeeder extends Seeder
{
    public function run(): void
    {
        foreach (config('due_service_reminders', []) as $preset) {
            WhatsAppMessageTemplate::query()->firstOrCreate(
                ['name' => $preset['name'], 'language' => $preset['language']],
                [
                    'category' => $preset['category'],
                    'status' => 'DRAFT',
                    'components' => [[
                        'type' => 'BODY',
                        'text' => $preset['body'],
                        'example' => ['body_text' => [['Sara']]],
                    ]],
                ],
            );
        }
    }
}
