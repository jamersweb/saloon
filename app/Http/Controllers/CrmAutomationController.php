<?php

namespace App\Http\Controllers;

use App\Models\Appointment;
use App\Models\Campaign;
use App\Models\CampaignTemplate;
use App\Models\CommunicationLog;
use App\Models\Customer;
use App\Models\CustomerDueService;
use App\Models\FinanceSetting;
use App\Models\CustomerSegmentRule;
use App\Models\CustomerTag;
use App\Models\WhatsAppMessageTemplate;
use App\Services\CampaignDispatchService;
use App\Services\CommunicationDeliveryService;
use App\Services\DueServiceManager;
use App\Services\WhatsAppService;
use App\Services\WhatsAppTemplateManagerService;
use App\Support\Audit;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class CrmAutomationController extends Controller
{
    public function __construct(
        private readonly WhatsAppService $whatsAppService,
    ) {
    }

    public function index(Request $request): Response
    {
        $filters = [
            'search' => trim((string) $request->string('search')),
            'tag_id' => $request->integer('tag_id') ?: null,
            'tag_state' => (string) $request->input('tag_state', 'all'),
            'active_status' => (string) $request->input('active_status', 'all'),
            'sort' => (string) $request->input('sort', 'name_asc'),
            'per_page' => (int) $request->input('per_page', 10),
        ];
        $contactFilters = [
            'search' => trim((string) $request->input('contact_search', '')),
            'tag_id' => $request->integer('contact_tag_id') ?: null,
            'tag_state' => (string) $request->input('contact_tag_state', 'all'),
            'active_status' => (string) $request->input('contact_active_status', 'active'),
            'per_page' => (int) $request->input('contact_per_page', 10),
        ];

        if (! in_array($filters['tag_state'], ['all', 'tagged', 'untagged'], true)) {
            $filters['tag_state'] = 'all';
        }

        if (! in_array($filters['active_status'], ['all', 'active', 'inactive'], true)) {
            $filters['active_status'] = 'all';
        }

        if (! in_array($filters['sort'], ['name_asc', 'name_desc', 'recent'], true)) {
            $filters['sort'] = 'name_asc';
        }

        if (! in_array($filters['per_page'], [10, 25, 50, 100], true)) {
            $filters['per_page'] = 10;
        }

        if (! in_array($contactFilters['tag_state'], ['all', 'tagged', 'untagged'], true)) {
            $contactFilters['tag_state'] = 'all';
        }

        if (! in_array($contactFilters['active_status'], ['all', 'active', 'inactive'], true)) {
            $contactFilters['active_status'] = 'active';
        }

        if (! in_array($contactFilters['per_page'], [10, 25, 50, 100], true)) {
            $contactFilters['per_page'] = 10;
        }

        $customers = Customer::query()
            ->with('tags:id,name,color')
            ->when($filters['search'] !== '', function ($query) use ($filters): void {
                $needle = '%' . $filters['search'] . '%';
                $query->where(function ($customerQuery) use ($needle): void {
                    $customerQuery
                        ->where('name', 'like', $needle)
                        ->orWhere('customer_code', 'like', $needle)
                        ->orWhere('phone', 'like', $needle)
                        ->orWhere('email', 'like', $needle);
                });
            })
            ->when($filters['tag_id'], fn ($query) => $query->whereHas('tags', fn ($tagQuery) => $tagQuery->where('customer_tags.id', $filters['tag_id'])))
            ->when($filters['tag_state'] === 'tagged', fn ($query) => $query->whereHas('tags'))
            ->when($filters['tag_state'] === 'untagged', fn ($query) => $query->doesntHave('tags'))
            ->when($filters['active_status'] === 'active', fn ($query) => $query->where('is_active', true))
            ->when($filters['active_status'] === 'inactive', fn ($query) => $query->where('is_active', false));

        match ($filters['sort']) {
            'name_desc' => $customers->orderByDesc('name'),
            'recent' => $customers->latest(),
            default => $customers->orderBy('name'),
        };

        $contacts = Customer::query()
            ->with('tags:id,name,color')
            ->when($contactFilters['search'] !== '', function ($query) use ($contactFilters): void {
                $needle = '%' . $contactFilters['search'] . '%';
                $query->where(function ($customerQuery) use ($needle): void {
                    $customerQuery
                        ->where('name', 'like', $needle)
                        ->orWhere('customer_code', 'like', $needle)
                        ->orWhere('phone', 'like', $needle)
                        ->orWhere('email', 'like', $needle);
                });
            })
            ->when($contactFilters['tag_id'], fn ($query) => $query->whereHas('tags', fn ($tagQuery) => $tagQuery->where('customer_tags.id', $contactFilters['tag_id'])))
            ->when($contactFilters['tag_state'] === 'tagged', fn ($query) => $query->whereHas('tags'))
            ->when($contactFilters['tag_state'] === 'untagged', fn ($query) => $query->doesntHave('tags'))
            ->when($contactFilters['active_status'] === 'active', fn ($query) => $query->where('is_active', true))
            ->when($contactFilters['active_status'] === 'inactive', fn ($query) => $query->where('is_active', false))
            ->orderBy('name');

        return Inertia::render('Customers/Automation', [
            'tags' => CustomerTag::query()->orderByDesc('is_active')->orderBy('name')->get(),
            'customerOptions' => Customer::query()
                ->orderBy('name')
                ->limit(500)
                ->get()
                ->map(fn (Customer $customer) => [
                    'id' => $customer->id,
                    'name' => $customer->name,
                ]),
            'customers' => $customers
                ->paginate($filters['per_page'])
                ->withQueryString()
                ->through(fn (Customer $customer) => [
                    'id' => $customer->id,
                    'customer_code' => $customer->customer_code,
                    'name' => $customer->name,
                    'phone' => $customer->phone,
                    'email' => $customer->email,
                    'is_active' => (bool) $customer->is_active,
                    'tags' => $customer->tags->map(fn ($tag) => [
                        'id' => $tag->id,
                        'name' => $tag->name,
                        'color' => $tag->color,
                    ])->values(),
                    'created_at' => $customer->created_at?->toIso8601String(),
                ]),
            'customerFilters' => $filters,
            'dueServices' => CustomerDueService::query()
                ->with(['customer:id,name,phone,email', 'service:id,name'])
                ->where('status', 'pending')
                ->orderBy('due_date')
                ->limit(250)
                ->get()
                ->map(fn (CustomerDueService $due) => [
                    'id' => $due->id,
                    'customer_id' => $due->customer_id,
                    'customer_name' => $due->customer?->name,
                    'customer_phone' => $due->customer?->phone,
                    'customer_email' => $due->customer?->email,
                    'service_name' => $due->service?->name,
                    'due_date' => $due->due_date,
                    'status' => $due->status,
                    'reminder_sent_at' => $due->reminder_sent_at,
                ]),
            'recentLogs' => CommunicationLog::query()
                ->with('customer:id,name')
                ->latest()
                ->limit(120)
                ->get()
                ->map(fn (CommunicationLog $log) => [
                    'id' => $log->id,
                    'customer_name' => $log->customer?->name,
                    'channel' => $log->channel,
                    'context' => $log->context,
                    'recipient' => $log->recipient,
                    'status' => $log->status,
                    'provider_status' => $log->provider_status,
                    'sent_at' => $log->sent_at,
                ]),
            'contacts' => $contacts
                ->paginate($contactFilters['per_page'], ['*'], 'contact_page')
                ->withQueryString()
                ->through(function (Customer $customer) {
                    $isReady = $this->isWhatsAppReady((string) ($customer->phone ?? ''));

                    return [
                        'id' => $customer->id,
                        'customer_code' => $customer->customer_code,
                        'name' => $customer->name,
                        'phone' => $customer->phone,
                        'email' => $customer->email,
                        'is_active' => (bool) $customer->is_active,
                        'tags' => $customer->tags->map(fn ($tag) => [
                            'id' => $tag->id,
                            'name' => $tag->name,
                            'color' => $tag->color,
                        ])->values(),
                        'whatsapp_ready' => $isReady,
                        'last_whatsapp_status' => CommunicationLog::query()
                            ->where('customer_id', $customer->id)
                            ->where('channel', 'whatsapp')
                            ->latest('id')
                            ->value('provider_status'),
                    ];
                }),
            'contactFilters' => $contactFilters,
            'segmentRules' => CustomerSegmentRule::query()
                ->with('tag:id,name,color')
                ->latest()
                ->get()
                ->map(fn (CustomerSegmentRule $rule) => [
                    'id' => $rule->id,
                    'name' => $rule->name,
                    'criteria' => $rule->criteria,
                    'threshold_value' => $rule->threshold_value,
                    'lookback_days' => $rule->lookback_days,
                    'is_active' => $rule->is_active,
                    'last_run_at' => $rule->last_run_at,
                    'tag_name' => $rule->tag?->name,
                    'tag_color' => $rule->tag?->color,
                    'preview_count' => $this->resolveRuleCustomerIds($rule)->count(),
                ]),
            'campaignTemplates' => CampaignTemplate::query()
                ->orderByDesc('is_active')
                ->orderBy('name')
                ->get(),
            'metaTemplates' => WhatsAppMessageTemplate::query()
                ->latest('updated_at')
                ->limit(200)
                ->get()
                ->map(fn (WhatsAppMessageTemplate $template) => [
                    'id' => $template->id,
                    'template_uid' => $template->template_uid,
                    'name' => $template->name,
                    'language' => $template->language,
                    'category' => $template->category,
                    'status' => $template->status,
                    'sub_category' => $template->sub_category,
                    'quality_score' => $template->quality_score,
                    'rejection_reason' => $template->rejection_reason,
                    'components' => $template->components,
                    'last_synced_at' => $template->last_synced_at,
                ]),
            'campaigns' => Campaign::query()
                ->with(['template:id,name', 'tag:id,name'])
                ->latest()
                ->limit(120)
                ->get()
                ->map(fn (Campaign $campaign) => [
                    'id' => $campaign->id,
                    'name' => $campaign->name,
                    'channel' => $campaign->channel,
                    'audience_type' => $campaign->audience_type,
                    'tag_name' => $campaign->tag?->name,
                    'inactivity_days' => $campaign->inactivity_days,
                    'template_name' => $campaign->template?->name,
                    'scheduled_at' => $campaign->scheduled_at,
                    'status' => $campaign->status,
                    'sent_count' => $campaign->sent_count,
                    'failed_count' => $campaign->failed_count,
                    'last_run_at' => $campaign->last_run_at,
                ]),
        ]);
    }

    public function storeCampaignTemplate(Request $request): RedirectResponse
    {
        $this->authorizeRoles($request, 'owner', 'manager');

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'channel' => ['required', 'in:sms,email,whatsapp'],
            'content' => ['required', 'string', 'max:2000'],
            'whatsapp_message_type' => ['nullable', 'in:text,template'],
            'whatsapp_template_name' => ['nullable', 'string', 'max:255'],
            'whatsapp_template_language_code' => ['nullable', 'string', 'max:16'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $template = CampaignTemplate::create([
            ...$data,
            'whatsapp_message_type' => $data['channel'] === 'whatsapp' ? ($data['whatsapp_message_type'] ?? 'text') : null,
            'whatsapp_template_name' => $data['channel'] === 'whatsapp' ? ($data['whatsapp_template_name'] ?? null) : null,
            'whatsapp_template_language_code' => $data['channel'] === 'whatsapp' ? ($data['whatsapp_template_language_code'] ?? null) : null,
            'is_active' => (bool) ($data['is_active'] ?? true),
        ]);

        Audit::log($request->user()?->id, 'campaign_template.created', 'CampaignTemplate', $template->id);

        return back()->with('status', 'Campaign template created.');
    }

    public function updateCampaignTemplate(Request $request, CampaignTemplate $template): RedirectResponse
    {
        $this->authorizeRoles($request, 'owner', 'manager');

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'channel' => ['required', 'in:sms,email,whatsapp'],
            'content' => ['required', 'string', 'max:2000'],
            'whatsapp_message_type' => ['nullable', 'in:text,template'],
            'whatsapp_template_name' => ['nullable', 'string', 'max:255'],
            'whatsapp_template_language_code' => ['nullable', 'string', 'max:16'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $template->update([
            ...$data,
            'whatsapp_message_type' => $data['channel'] === 'whatsapp' ? ($data['whatsapp_message_type'] ?? 'text') : null,
            'whatsapp_template_name' => $data['channel'] === 'whatsapp' ? ($data['whatsapp_template_name'] ?? null) : null,
            'whatsapp_template_language_code' => $data['channel'] === 'whatsapp' ? ($data['whatsapp_template_language_code'] ?? null) : null,
            'is_active' => (bool) ($data['is_active'] ?? false),
        ]);

        Audit::log($request->user()?->id, 'campaign_template.updated', 'CampaignTemplate', $template->id);

        return back()->with('status', 'Campaign template updated.');
    }

    public function storeCampaign(Request $request): RedirectResponse
    {
        $this->authorizeRoles($request, 'owner', 'manager');

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'campaign_template_id' => ['required', 'exists:campaign_templates,id'],
            'audience_type' => ['required', 'in:all,tag,due_service,inactivity_days'],
            'customer_tag_id' => ['nullable', 'exists:customer_tags,id'],
            'inactivity_days' => ['nullable', 'integer', 'min:1', 'max:3650'],
            'scheduled_at' => ['nullable', 'date'],
        ]);

        $template = CampaignTemplate::findOrFail((int) $data['campaign_template_id']);

        if ($data['audience_type'] === 'tag' && empty($data['customer_tag_id'])) {
            return back()->withErrors(['customer_tag_id' => 'Tag is required for tag-based audience.']);
        }

        if ($data['audience_type'] === 'inactivity_days' && empty($data['inactivity_days'])) {
            return back()->withErrors(['inactivity_days' => 'Inactivity days is required for inactivity audience.']);
        }

        $campaign = Campaign::create([
            'name' => $data['name'],
            'campaign_template_id' => (int) $data['campaign_template_id'],
            'channel' => $template->channel,
            'audience_type' => $data['audience_type'],
            'customer_tag_id' => $data['audience_type'] === 'tag' ? (int) $data['customer_tag_id'] : null,
            'inactivity_days' => $data['audience_type'] === 'inactivity_days' ? (int) $data['inactivity_days'] : null,
            'scheduled_at' => $data['scheduled_at'] ?? null,
            'status' => ! empty($data['scheduled_at']) ? 'scheduled' : 'draft',
            'created_by' => $request->user()?->id,
        ]);

        Audit::log($request->user()?->id, 'campaign.created', 'Campaign', $campaign->id);

        return back()->with('status', 'Campaign created.');
    }

    public function syncMetaTemplates(Request $request, WhatsAppTemplateManagerService $templateManagerService): RedirectResponse
    {
        $this->authorizeRoles($request, 'owner', 'manager');

        $templates = $templateManagerService->syncFromMeta();

        Audit::log($request->user()?->id, 'whatsapp.templates.synced', 'WhatsAppMessageTemplate', null, [
            'count' => count($templates),
        ]);

        return back()->with('status', 'Meta templates synced.');
    }

    public function storeMetaTemplate(Request $request, WhatsAppTemplateManagerService $templateManagerService): RedirectResponse
    {
        $this->authorizeRoles($request, 'owner', 'manager');

        $data = $this->validateMetaTemplatePayload($request);
        $components = $this->buildMetaTemplateComponents($data);

        $template = $templateManagerService->createTemplate(
            strtolower((string) $data['name']),
            (string) $data['language'],
            (string) $data['category'],
            $components,
        );

        Audit::log($request->user()?->id, 'whatsapp.template.created', 'WhatsAppMessageTemplate', null, [
            'name' => $template['name'] ?? $data['name'],
            'language' => $template['language'] ?? $data['language'],
        ]);

        return back()->with('status', 'Meta template submitted.');
    }

    public function updateMetaTemplate(Request $request, WhatsAppMessageTemplate $template, WhatsAppTemplateManagerService $templateManagerService): RedirectResponse
    {
        $this->authorizeRoles($request, 'owner', 'manager');

        $data = $this->validateMetaTemplatePayload($request);
        $components = $this->buildMetaTemplateComponents($data);

        $updated = $templateManagerService->replaceTemplate(
            $template,
            strtolower((string) $data['name']),
            (string) $data['language'],
            (string) $data['category'],
            $components,
        );

        Audit::log($request->user()?->id, 'whatsapp.template.replaced', 'WhatsAppMessageTemplate', $template->id, [
            'name' => $updated['name'] ?? $data['name'],
            'language' => $updated['language'] ?? $data['language'],
        ]);

        return back()->with('status', 'Meta template replaced.');
    }

    public function uploadMetaTemplateHeaderMedia(Request $request, WhatsAppTemplateManagerService $templateManagerService): RedirectResponse
    {
        $this->authorizeRoles($request, 'owner', 'manager');

        $data = $request->validate([
            'header_type' => ['required', 'in:image,video,document'],
            'header_media_file' => ['required', 'file', 'max:16384'],
        ]);

        $handle = $templateManagerService->uploadHeaderSample($data['header_media_file']);

        Audit::log($request->user()?->id, 'whatsapp.template.header_media_uploaded', 'WhatsAppMessageTemplate', null, [
            'header_type' => $data['header_type'],
            'file_name' => $data['header_media_file']->getClientOriginalName(),
        ]);

        return back()
            ->with('status', 'Header sample uploaded to Meta.')
            ->with('whatsapp_header_media_handle', $handle);
    }

    public function destroyMetaTemplate(Request $request, WhatsAppMessageTemplate $template, WhatsAppTemplateManagerService $templateManagerService): RedirectResponse
    {
        $this->authorizeRoles($request, 'owner', 'manager');

        $templateManagerService->deleteTemplate($template);

        Audit::log($request->user()?->id, 'whatsapp.template.deleted', 'WhatsAppMessageTemplate', $template->id, [
            'name' => $template->name,
            'language' => $template->language,
        ]);

        return back()->with('status', 'Meta template deleted.');
    }

    public function dispatchCampaign(Request $request, Campaign $campaign, CampaignDispatchService $dispatcher): RedirectResponse
    {
        $this->authorizeRoles($request, 'owner', 'manager');

        if ($campaign->status === 'cancelled') {
            return back()->withErrors(['status' => 'Cancelled campaign cannot be dispatched.']);
        }

        $result = $dispatcher->dispatch($campaign);
        Audit::log($request->user()?->id, 'campaign.dispatched', 'Campaign', $campaign->id, $result);

        return back()->with('status', "Campaign queued. Jobs: {$result['queued']}.");
    }

    public function runScheduledCampaigns(Request $request, CampaignDispatchService $dispatcher): RedirectResponse
    {
        $this->authorizeRoles($request, 'owner', 'manager');

        $campaigns = Campaign::query()
            ->where('status', 'scheduled')
            ->whereNotNull('scheduled_at')
            ->where('scheduled_at', '<=', now())
            ->orderBy('scheduled_at')
            ->limit(20)
            ->get();

        $queued = 0;

        foreach ($campaigns as $campaign) {
            $result = $dispatcher->dispatch($campaign);
            $queued += $result['queued'];
        }

        Audit::log($request->user()?->id, 'campaign.scheduled_dispatched', 'Campaign', null, ['queued' => $queued]);

        return back()->with('status', "Scheduled campaigns queued. Jobs: {$queued}.");
    }

    public function storeTag(Request $request): RedirectResponse
    {
        $this->authorizeRoles($request, 'owner', 'manager');

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255', 'unique:customer_tags,name'],
            'color' => ['nullable', 'string', 'max:20'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $tag = CustomerTag::create([
            'name' => $data['name'],
            'color' => $data['color'] ?? '#4f46e5',
            'is_active' => (bool) ($data['is_active'] ?? true),
        ]);

        Audit::log($request->user()?->id, 'customer_tag.created', 'CustomerTag', $tag->id);

        return back()->with('status', 'Tag created.');
    }

    public function assignTag(Request $request): RedirectResponse
    {
        $this->authorizeRoles($request, 'owner', 'manager', 'staff');

        $data = $request->validate([
            'customer_id' => ['required', 'exists:customers,id'],
            'customer_tag_id' => ['required', 'exists:customer_tags,id'],
        ]);

        $customer = Customer::findOrFail($data['customer_id']);
        $customer->tags()->syncWithoutDetaching([
            $data['customer_tag_id'] => ['assigned_by' => $request->user()?->id],
        ]);

        Audit::log($request->user()?->id, 'customer_tag.assigned', 'Customer', (int) $data['customer_id'], [
            'tag_id' => (int) $data['customer_tag_id'],
        ]);

        return back()->with('status', 'Tag assigned.');
    }

    public function removeTag(Request $request): RedirectResponse
    {
        $this->authorizeRoles($request, 'owner', 'manager', 'staff');

        $data = $request->validate([
            'customer_id' => ['required', 'exists:customers,id'],
            'customer_tag_id' => ['required', 'exists:customer_tags,id'],
        ]);

        $customer = Customer::findOrFail($data['customer_id']);
        $customer->tags()->detach([(int) $data['customer_tag_id']]);

        Audit::log($request->user()?->id, 'customer_tag.removed', 'Customer', (int) $data['customer_id'], [
            'tag_id' => (int) $data['customer_tag_id'],
        ]);

        return back()->with('status', 'Tag removed.');
    }

    public function generateDueServices(Request $request, DueServiceManager $dueServiceManager): RedirectResponse
    {
        $this->authorizeRoles($request, 'owner', 'manager');

        $count = $dueServiceManager->backfillCompletedAppointments();

        Audit::log($request->user()?->id, 'due_service.generated', 'CustomerDueService', null, ['rows' => $count]);

        return back()->with('status', 'Due services generated/refreshed.');
    }

    public function sendReminder(Request $request, CustomerDueService $dueService, CommunicationDeliveryService $communicationDeliveryService): RedirectResponse
    {
        $this->authorizeRoles($request, 'owner', 'manager', 'staff');

        $data = $request->validate([
            'channel' => ['nullable', 'in:sms,email,whatsapp'],
            'policy' => ['nullable', 'in:single,fallback_email'],
        ]);

        $dueService->loadMissing(['customer', 'service']);

        $channel = $data['channel'] ?? 'sms';
        $policy = $data['policy'] ?? 'single';
        $recipient = $this->recipientForChannel($dueService, $channel);

        if (! $recipient && $policy === 'fallback_email' && $channel !== 'email') {
            $channel = 'email';
            $recipient = $this->recipientForChannel($dueService, $channel);
        }

        if (! $recipient) {
            return back()->withErrors(['channel' => 'No recipient available for selected reminder policy.']);
        }

        $log = $communicationDeliveryService->deliver(
            $dueService->customer,
            $channel,
            $recipient,
            sprintf('Hi %s, your %s service is due on %s.', $dueService->customer?->name ?? 'Customer', $dueService->service?->name ?? 'service', $dueService->due_date?->toDateString()),
            'due_service_reminder:' . $dueService->id,
            $this->deliveryOptionsForReminder($channel, $dueService),
        );

        if (! in_array($log->status, ['queued', 'sent'], true)) {
            return back()->withErrors(['channel' => $log->error_message ?? 'Message delivery failed.']);
        }

        Audit::log($request->user()?->id, 'due_service.reminder_sent', 'CustomerDueService', $dueService->id, ['channel' => $channel, 'policy' => $policy]);

        return back()->with('status', $log->status === 'queued' ? 'Reminder queued for delivery.' : 'Reminder logged as sent.');
    }

    public function sendSingleMessage(Request $request, CommunicationDeliveryService $communicationDeliveryService): RedirectResponse
    {
        $this->authorizeRoles($request, 'owner', 'manager', 'staff');

        $data = $request->validate([
            'customer_id' => ['required', 'exists:customers,id'],
            'channel' => ['required', 'in:sms,email,whatsapp'],
            'message' => ['nullable', 'string', 'max:2000'],
            'whatsapp_message_type' => ['nullable', 'in:text,template'],
            'whatsapp_template_id' => ['nullable', 'exists:whatsapp_message_templates,id'],
            'whatsapp_template_variables' => ['nullable', 'string', 'max:1000'],
        ]);

        $customer = Customer::findOrFail((int) $data['customer_id']);
        $channel = (string) $data['channel'];
        $messageType = $channel === 'whatsapp'
            ? (string) ($data['whatsapp_message_type'] ?? 'text')
            : 'text';

        if ($messageType === 'template' && empty($data['whatsapp_template_id'])) {
            return back()->withErrors(['whatsapp_template_id' => 'Select a WhatsApp template.']);
        }

        if ($messageType === 'text' && blank($data['message'] ?? null)) {
            return back()->withErrors(['message' => 'Message content is required.']);
        }

        $recipient = $this->recipientForCustomerChannel($customer, $channel);
        $deliveryOptions = $this->deliveryOptionsForSingleMessage($channel, $messageType, $data);
        $log = $communicationDeliveryService->deliver(
            $customer,
            $channel,
            $recipient,
            (string) ($data['message'] ?? ($messageType === 'template' ? 'WhatsApp template message' : '')),
            'single_message:' . $customer->id,
            $deliveryOptions,
        );

        if (! in_array($log->status, ['queued', 'sent'], true)) {
            return back()->withErrors(['message' => $log->error_message ?? 'Message delivery failed.']);
        }

        Audit::log($request->user()?->id, 'customer.single_message_sent', 'Customer', $customer->id, [
            'channel' => $channel,
            'message_type' => $messageType,
            'communication_log_id' => $log->id,
        ]);

        return back()->with('status', $log->status === 'queued' ? 'Message queued for delivery.' : 'Message sent.');
    }

    public function updateDueStatus(Request $request, CustomerDueService $dueService): RedirectResponse
    {
        $this->authorizeRoles($request, 'owner', 'manager', 'staff');

        $data = $request->validate([
            'status' => ['required', 'in:pending,booked,dismissed'],
        ]);

        $dueService->update(['status' => $data['status']]);

        Audit::log($request->user()?->id, 'due_service.status_updated', 'CustomerDueService', $dueService->id, ['status' => $data['status']]);

        return back()->with('status', 'Due service updated.');
    }

    public function storeSegmentRule(Request $request): RedirectResponse
    {
        $this->authorizeRoles($request, 'owner', 'manager');

        $data = $this->validateSegmentRulePayload($request);

        $rule = CustomerSegmentRule::create([
            ...$data,
            'is_active' => (bool) ($data['is_active'] ?? true),
        ]);

        Audit::log($request->user()?->id, 'segment_rule.created', 'CustomerSegmentRule', $rule->id);

        return back()->with('status', 'Segment rule created.');
    }

    public function updateSegmentRule(Request $request, CustomerSegmentRule $rule): RedirectResponse
    {
        $this->authorizeRoles($request, 'owner', 'manager');

        $data = $this->validateSegmentRulePayload($request);

        $rule->update([
            ...$data,
            'is_active' => (bool) ($data['is_active'] ?? false),
        ]);

        Audit::log($request->user()?->id, 'segment_rule.updated', 'CustomerSegmentRule', $rule->id);

        return back()->with('status', 'Segment rule updated.');
    }

    public function deactivateSegmentRule(Request $request, CustomerSegmentRule $rule): RedirectResponse
    {
        $this->authorizeRoles($request, 'owner', 'manager');

        $rule->update(['is_active' => false]);

        Audit::log($request->user()?->id, 'segment_rule.deactivated', 'CustomerSegmentRule', $rule->id);

        return back()->with('status', 'Segment rule deactivated.');
    }

    public function previewSegmentRule(Request $request, CustomerSegmentRule $rule): RedirectResponse
    {
        $this->authorizeRoles($request, 'owner', 'manager');

        $count = $this->resolveRuleCustomerIds($rule)->count();

        return back()->with('status', "Preview: {$count} customers match rule \"{$rule->name}\".");
    }

    public function runSegmentRules(Request $request): RedirectResponse
    {
        $this->authorizeRoles($request, 'owner', 'manager');

        $data = $request->validate([
            'rule_id' => ['nullable', 'exists:customer_segment_rules,id'],
        ]);

        $rules = CustomerSegmentRule::query()
            ->where('is_active', true)
            ->when(isset($data['rule_id']), fn ($q) => $q->where('id', (int) $data['rule_id']))
            ->get();
        $applied = 0;

        foreach ($rules as $rule) {
            $matches = $this->resolveRuleCustomerIds($rule);
            if ($matches->isEmpty()) {
                $rule->update(['last_run_at' => now()]);
                continue;
            }

            foreach ($matches as $customerId) {
                $customer = Customer::find($customerId);
                if (! $customer) {
                    continue;
                }

                $customer->tags()->syncWithoutDetaching([
                    $rule->customer_tag_id => ['assigned_by' => $request->user()?->id],
                ]);
                $applied++;
            }

            $rule->update(['last_run_at' => now()]);
        }

        Audit::log($request->user()?->id, 'segment_rule.executed', 'CustomerSegmentRule', null, ['assignments' => $applied]);

        return back()->with('status', "Segment rules executed. {$applied} tag assignments applied.");
    }

    private function validateSegmentRulePayload(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'customer_tag_id' => ['required', 'exists:customer_tags,id'],
            'criteria' => ['required', 'in:inactivity_days,min_spend,min_visits'],
            'threshold_value' => ['required', 'numeric', 'min:1'],
            'lookback_days' => ['nullable', 'integer', 'min:1', 'max:3650'],
            'is_active' => ['nullable', 'boolean'],
        ]);
    }

    private function resolveRuleCustomerIds(CustomerSegmentRule $rule)
    {
        $customerCreatedAtExpr = "'1970-01-01 00:00:00'";

        return match ($rule->criteria) {
            'inactivity_days' => Customer::query()
                ->leftJoin('appointments', function ($join): void {
                    $join->on('customers.id', '=', 'appointments.customer_id')
                        ->where('appointments.status', Appointment::STATUS_COMPLETED);
                })
                ->select('customers.id')
                ->groupBy('customers.id')
                ->havingRaw("COALESCE(MAX(appointments.scheduled_start), {$customerCreatedAtExpr}) <= ?", [now()->subDays((int) $rule->threshold_value)->toDateTimeString()])
                ->pluck('customers.id'),
            'min_spend' => Customer::query()
                ->join('appointments', 'customers.id', '=', 'appointments.customer_id')
                ->join('salon_services', 'appointments.service_id', '=', 'salon_services.id')
                ->where('appointments.status', Appointment::STATUS_COMPLETED)
                ->when($rule->lookback_days, fn ($q) => $q->where('appointments.scheduled_start', '>=', now()->subDays((int) $rule->lookback_days)))
                ->groupBy('customers.id')
                ->havingRaw('SUM(salon_services.price) >= ?', [(float) $rule->threshold_value])
                ->pluck('customers.id'),
            default => Customer::query()
                ->join('appointments', 'customers.id', '=', 'appointments.customer_id')
                ->where('appointments.status', Appointment::STATUS_COMPLETED)
                ->when($rule->lookback_days, fn ($q) => $q->where('appointments.scheduled_start', '>=', now()->subDays((int) $rule->lookback_days)))
                ->groupBy('customers.id')
                ->havingRaw('COUNT(appointments.id) >= ?', [(int) $rule->threshold_value])
                ->pluck('customers.id'),
        };
    }

    private function recipientForChannel(CustomerDueService $dueService, string $channel): ?string
    {
        return $channel === 'email'
            ? $dueService->customer?->email
            : $dueService->customer?->phone;
    }

    private function recipientForCustomerChannel(Customer $customer, string $channel): ?string
    {
        return $channel === 'email'
            ? $customer->email
            : $customer->phone;
    }

    /**
     * @return array<string, mixed>
     */
    private function deliveryOptionsForReminder(string $channel, CustomerDueService $dueService): array
    {
        if ($channel !== 'whatsapp') {
            return [];
        }

        $settings = FinanceSetting::current();
        $templateName = $settings->whatsapp_due_service_template_name;
        $languageCode = $settings->whatsapp_default_language_code ?: config('services.whatsapp.default_language_code', 'en_US');

        if (filled($templateName)) {
            return [
                'async' => true,
                'message_type' => 'template',
                'template_name' => $templateName,
                'language_code' => $languageCode,
                'components' => [
                    [
                        'type' => 'body',
                        'parameters' => [
                            ['type' => 'text', 'text' => (string) ($dueService->customer?->name ?? 'Customer')],
                            ['type' => 'text', 'text' => (string) ($dueService->service?->name ?? 'service')],
                            ['type' => 'text', 'text' => (string) $dueService->due_date?->toDateString()],
                        ],
                    ],
                ],
            ];
        }

        return [
            'async' => true,
            'message_type' => 'text',
        ];
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function deliveryOptionsForSingleMessage(string $channel, string $messageType, array $data): array
    {
        if ($channel !== 'whatsapp') {
            return [];
        }

        if ($messageType !== 'template') {
            return [
                'async' => true,
                'message_type' => 'text',
            ];
        }

        $template = WhatsAppMessageTemplate::findOrFail((int) $data['whatsapp_template_id']);
        $variables = collect(explode(',', (string) ($data['whatsapp_template_variables'] ?? '')))
            ->map(fn (string $value) => trim($value))
            ->filter()
            ->values();

        return [
            'async' => true,
            'message_type' => 'template',
            'template_name' => $template->name,
            'language_code' => $template->language,
            'components' => $variables->isEmpty() ? [] : [[
                'type' => 'body',
                'parameters' => $variables
                    ->map(fn (string $value) => ['type' => 'text', 'text' => $value])
                    ->values()
                    ->all(),
            ]],
        ];
    }

    private function isWhatsAppReady(string $phone): bool
    {
        if ($phone === '') {
            return false;
        }

        try {
            $this->whatsAppService->normalizeRecipientForTransport($phone);

            return true;
        } catch (\InvalidArgumentException) {
            return false;
        }
    }

    private function validateMetaTemplatePayload(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:512', 'regex:/^[a-z0-9_]+$/'],
            'language' => ['required', 'string', 'max:16'],
            'category' => ['required', 'in:MARKETING,UTILITY,AUTHENTICATION'],
            'header_type' => ['nullable', 'in:none,text,image,video,document'],
            'header_text' => ['nullable', 'string', 'max:60'],
            'header_example' => ['nullable', 'string', 'max:255'],
            'header_media_handle' => ['nullable', 'string', 'max:4096', 'required_if:header_type,image,video,document'],
            'body_text' => ['required', 'string', 'max:1024'],
            'footer_text' => ['nullable', 'string', 'max:60'],
            'example_values' => ['nullable', 'string', 'max:1000'],
            'buttons' => ['nullable', 'array', 'max:3'],
            'buttons.*.type' => ['nullable', 'in:QUICK_REPLY,URL,PHONE_NUMBER'],
            'buttons.*.text' => ['nullable', 'string', 'max:25'],
            'buttons.*.url' => ['nullable', 'string', 'max:2000'],
            'buttons.*.phone_number' => ['nullable', 'string', 'max:30'],
        ]);
    }

    /**
     * @param array<string, mixed> $data
     * @return array<int, array<string, mixed>>
     */
    private function buildMetaTemplateComponents(array $data): array
    {
        $exampleValues = collect(explode(',', (string) ($data['example_values'] ?? '')))
            ->map(fn (string $value) => trim($value))
            ->filter()
            ->values()
            ->all();

        $components = [];

        if (($data['header_type'] ?? 'none') === 'text' && filled($data['header_text'] ?? null)) {
            $header = [
                'type' => 'HEADER',
                'format' => 'TEXT',
                'text' => $data['header_text'],
            ];

            if (filled($data['header_example'] ?? null)) {
                $header['example'] = [
                    'header_text' => [(string) $data['header_example']],
                ];
            }

            $components[] = $header;
        }

        if (in_array(($data['header_type'] ?? 'none'), ['image', 'video', 'document'], true)) {
            $components[] = [
                'type' => 'HEADER',
                'format' => strtoupper((string) $data['header_type']),
                'example' => [
                    'header_handle' => [(string) $data['header_media_handle']],
                ],
            ];
        }

        $body = [
            'type' => 'BODY',
            'text' => $data['body_text'],
        ];

        if ($exampleValues !== []) {
            $body['example'] = [
                'body_text' => [$exampleValues],
            ];
        }

        $components[] = $body;

        if (filled($data['footer_text'] ?? null)) {
            $components[] = [
                'type' => 'FOOTER',
                'text' => $data['footer_text'],
            ];
        }

        $buttons = collect($data['buttons'] ?? [])
            ->filter(fn ($button) => is_array($button) && filled($button['text'] ?? null) && filled($button['type'] ?? null))
            ->values()
            ->map(function (array $button, int $index): array {
                $payload = [
                    'type' => $button['type'],
                    'text' => $button['text'],
                ];

                if (($button['type'] ?? null) === 'URL' && filled($button['url'] ?? null)) {
                    $payload['url'] = $button['url'];
                }

                if (($button['type'] ?? null) === 'PHONE_NUMBER' && filled($button['phone_number'] ?? null)) {
                    $payload['phone_number'] = $button['phone_number'];
                }

                return array_merge(['index' => (string) $index], $payload);
            })
            ->all();

        if ($buttons !== []) {
            $components[] = [
                'type' => 'BUTTONS',
                'buttons' => $buttons,
            ];
        }

        return $components;
    }
}
