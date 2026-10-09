<?php

namespace App\Services;

use App\Models\FinanceSetting;
use App\Models\WhatsAppMessageTemplate;
use InvalidArgumentException;

class WhatsAppTemplateValidator
{
    public function validate(string $name, string $language, array $components): void
    {
        if (! preg_match('/^[a-z0-9_]+$/', $name)) {
            throw new InvalidArgumentException('Select the exact approved template name (lowercase letters, numbers and underscores only).');
        }

        $template = WhatsAppMessageTemplate::query()->where('name', $name)->where('language', $language)->first();
        if (! $template || strtoupper((string) $template->status) !== 'APPROVED') {
            throw new InvalidArgumentException('Template is not approved or not synced for this language. Sync WhatsApp templates and select an approved template.');
        }
        $waba = FinanceSetting::current()->whatsapp_business_account_id ?: config('services.whatsapp.business_account_id');
        $templateWaba = data_get($template->raw_payload, 'wabaId');
        if ($waba && $templateWaba && (string) $waba !== (string) $templateWaba) {
            throw new InvalidArgumentException('Template belongs to a different WhatsApp business account. Sync templates again.');
        }

        $expected = [];
        $buttons = [];
        foreach ($template->components ?? [] as $definition) {
            $type = strtolower($definition['type'] ?? '');
            if ($type === 'buttons') {
                $buttons = $definition['buttons'] ?? [];
            }
            if (! in_array($type, ['body', 'header'], true)) {
                continue;
            }
            $expected[$type] = true;
            $matches = array_values(array_filter($components, fn ($c) => strtolower($c['type'] ?? '') === $type));
            if (count($matches) > 1) {
                throw new InvalidArgumentException("Duplicate template {$type} component.");
            }
            $parameters = $matches[0]['parameters'] ?? [];
            $format = strtolower($definition['format'] ?? 'text');
            if ($type === 'header' && in_array($format, ['image', 'video', 'document'], true)) {
                $url = $parameters[0][$format]['link'] ?? '';
                if (count($parameters) !== 1 || ($parameters[0]['type'] ?? '') !== $format
                    || ! filter_var($url, FILTER_VALIDATE_URL) || parse_url($url, PHP_URL_SCHEME) !== 'https') {
                    throw new InvalidArgumentException("Template header requires one {$format} parameter with a public HTTPS media URL.");
                }

                continue;
            }
            preg_match_all('/{{\s*([^{}]+?)\s*}}/', $definition['text'] ?? '', $variables);
            $names = array_values(array_unique($variables[1]));
            if (count($parameters) !== count($names)) {
                throw new InvalidArgumentException("Template {$type} requires ".count($names).' parameters; '.count($parameters).' provided.');
            }
            foreach ($parameters as $index => $parameter) {
                if (($parameter['type'] ?? '') !== 'text' || trim((string) ($parameter['text'] ?? '')) === '') {
                    throw new InvalidArgumentException("Template {$type} parameters must contain non-empty text.");
                }
                if (! ctype_digit($names[$index]) && ($parameter['parameter_name'] ?? '') !== $names[$index]) {
                    throw new InvalidArgumentException("Template {$type} requires named parameter {$names[$index]}.");
                }
            }
        }
        if (! isset($expected['body'])) {
            throw new InvalidArgumentException('Template body metadata is missing. Sync templates before sending.');
        }
        foreach ($buttons as $index => $button) {
            if (strtoupper($button['type'] ?? '') !== 'URL' || ! str_contains($button['url'] ?? '', '{{')) {
                continue;
            }
            $supplied = array_values(array_filter($components, fn ($c) => strtolower($c['type'] ?? '') === 'button' && (string) ($c['index'] ?? '') === (string) $index));
            $parameter = $supplied[0]['parameters'][0] ?? [];
            if (count($supplied) !== 1 || ($supplied[0]['sub_type'] ?? '') !== 'url'
                || count($supplied[0]['parameters'] ?? []) !== 1 || ($parameter['type'] ?? '') !== 'text' || blank($parameter['text'] ?? null)) {
                throw new InvalidArgumentException("Template URL button {$index} requires a text parameter. Choose a supported template or supply its button parameter.");
            }
        }
        foreach ($components as $component) {
            $type = strtolower($component['type'] ?? '');
            if (in_array($type, ['body', 'header'], true) && ! isset($expected[$type])) {
                throw new InvalidArgumentException("Template does not define a {$type} component. Sync templates before sending.");
            }
        }
    }
}
