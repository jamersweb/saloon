<?php

namespace App\Support;

class Permissions
{
    /**
     * @return array<string, string>
     */
    public static function all(): array
    {
        return [
            'can_operate_frontdesk' => 'Operate appointments, customers, attendance, and leave requests',
            'can_manage_services' => 'Manage services',
            'can_manage_staff' => 'Manage staff profiles',
            'can_manage_schedules' => 'Manage schedules and booking rules',
            'can_manage_inventory' => 'Manage inventory and stock alerts',
            'can_manage_procurement' => 'Manage suppliers and purchase orders',
            'can_manage_loyalty' => 'Manage loyalty program',
            'can_manage_crm_automation' => 'Manage CRM automation and campaigns',
            'can_export_reports' => 'Access and export reports',
            'can_review_leave_requests' => 'Approve or reject leave requests',
            'can_manage_roles' => 'Create roles and assign permissions',
        ];
    }

    /**
     * @return list<string>
     */
    public static function defaultsForRole(string $roleName): array
    {
        return match ($roleName) {
            'owner' => array_keys(self::all()),
            'manager' => [
                'can_operate_frontdesk',
                'can_manage_services',
                'can_manage_staff',
                'can_manage_schedules',
                'can_manage_inventory',
                'can_manage_procurement',
                'can_manage_loyalty',
                'can_manage_crm_automation',
                'can_export_reports',
                'can_review_leave_requests',
            ],
            'staff' => [
                'can_operate_frontdesk',
            ],
            default => [],
        };
    }

    public static function routePermissionKey(?string $routeName): ?string
    {
        if (! $routeName) {
            return null;
        }

        if (str_starts_with($routeName, 'customers.automation.')) {
            return 'can_manage_crm_automation';
        }

        if (str_starts_with($routeName, 'appointments.')
            || str_starts_with($routeName, 'customers.')
            || str_starts_with($routeName, 'attendance.')
            || str_starts_with($routeName, 'leave-requests.')) {
            return str_starts_with($routeName, 'leave-requests.review')
                ? 'can_review_leave_requests'
                : 'can_operate_frontdesk';
        }

        return match (true) {
            str_starts_with($routeName, 'services.') => 'can_manage_services',
            str_starts_with($routeName, 'staff.') => 'can_manage_staff',
            str_starts_with($routeName, 'schedules.'),
            str_starts_with($routeName, 'booking-rules.') => 'can_manage_schedules',
            str_starts_with($routeName, 'inventory.') => 'can_manage_inventory',
            str_starts_with($routeName, 'suppliers.'),
            str_starts_with($routeName, 'purchase-orders.') => 'can_manage_procurement',
            str_starts_with($routeName, 'loyalty.') => 'can_manage_loyalty',
            str_starts_with($routeName, 'customers.automation.') => 'can_manage_crm_automation',
            str_starts_with($routeName, 'reports.') => 'can_export_reports',
            str_starts_with($routeName, 'roles.') => 'can_manage_roles',
            default => null,
        };
    }

    /**
     * @param  array<int, string>|null  $permissions
     * @return list<string>
     */
    public static function normalize(?array $permissions): array
    {
        $allowed = array_keys(self::all());

        return collect($permissions ?? [])
            ->filter(fn ($permission) => is_string($permission) && in_array($permission, $allowed, true))
            ->values()
            ->all();
    }
}
