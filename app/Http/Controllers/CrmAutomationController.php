<?php

namespace App\Http\Controllers;

use App\Models\Appointment;
use App\Models\Campaign;
use App\Models\CampaignTemplate;
use App\Models\CommunicationLog;
use App\Models\Customer;
use App\Models\CustomerDueService;
use App\Models\CustomerSegmentRule;
use App\Models\CustomerTag;
use App\Services\CampaignDispatchService;
use App\Services\CommunicationDeliveryService;
use App\Services\DueServiceManager;
use App\Support\Audit;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class CrmAutomationController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Customers/Automation', [
            'tags' => CustomerTag::query()->orderByDesc('is_active')->orderBy('name')->get(),
            'customers' => Customer::query()
                ->with('tags:id,name,color')
                ->orderBy('name')
                ->limit(250)
                ->get()
                ->map(fn (Customer $customer) => [
                    'id' => $customer->id,
                    'name' => $customer->name,
                    'phone' => $customer->phone,
                    'email' => $customer->email,
                    'tags' => $customer->tags,
                ]),
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
                    'sent_at' => $log->sent_at,
                ]),
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
            'is_active' => ['nullable', 'boolean'],
        ]);

        $template = CampaignTemplate::create([
            ...$data,
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
            'is_active' => ['nullable', 'boolean'],
        ]);

        $template->update([
            ...$data,
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

    public function dispatchCampaign(Request $request, Campaign $campaign, CampaignDispatchService $dispatcher): RedirectResponse
    {
        $this->authorizeRoles($request, 'owner', 'manager');

        if ($campaign->status === 'cancelled') {
            return back()->withErrors(['status' => 'Cancelled campaign cannot be dispatched.']);
        }

        $result = $dispatcher->dispatch($campaign);
        Audit::log($request->user()?->id, 'campaign.dispatched', 'Campaign', $campaign->id, $result);

        return back()->with('status', "Campaign dispatched. Sent: {$result['sent']}, Failed: {$result['failed']}.");
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

        $sent = 0;
        $failed = 0;

        foreach ($campaigns as $campaign) {
            $result = $dispatcher->dispatch($campaign);
            $sent += $result['sent'];
            $failed += $result['failed'];
        }

        Audit::log($request->user()?->id, 'campaign.scheduled_dispatched', 'Campaign', null, ['sent' => $sent, 'failed' => $failed]);

        return back()->with('status', "Scheduled campaigns dispatched. Sent: {$sent}, Failed: {$failed}.");
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
            'due_service_reminder',
        );

        if ($log->status !== 'sent') {
            return back()->withErrors(['channel' => $log->error_message ?? 'Message delivery failed.']);
        }

        $dueService->update(['reminder_sent_at' => now()]);

        Audit::log($request->user()?->id, 'due_service.reminder_sent', 'CustomerDueService', $dueService->id, ['channel' => $channel, 'policy' => $policy]);

        return back()->with('status', 'Reminder logged as sent.');
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
}
