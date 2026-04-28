import ApplicationLogo from '@/Components/ApplicationLogo';
import AppFlashPopup from '@/Components/AppFlashPopup';
import Dropdown from '@/Components/Dropdown';
import { LOYALTY_SECTIONS } from '@/Pages/Loyalty/loyaltySections';
import { Link, usePage } from '@inertiajs/react';
import { useEffect, useMemo, useState } from 'react';

const navBase = 'group flex items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium transition';

function NavItem({ href, active, children }) {
    return (
        <Link href={href} className={`${navBase} ${active ? 'bg-indigo-50 text-indigo-600' : 'text-slate-600 hover:bg-slate-100 hover:text-slate-800'}`}>
            <span className={`h-2 w-2 rounded-full ${active ? 'bg-indigo-500' : 'bg-slate-300 group-hover:bg-slate-500'}`} />
            {children}
        </Link>
    );
}

function NavGroup({ title, open, active, onToggle, children }) {
    return (
        <section className="space-y-2">
            <button
                type="button"
                onClick={onToggle}
                className={`flex w-full items-center justify-between rounded-lg px-2 py-1.5 text-xs font-semibold uppercase tracking-[0.14em] transition ${active ? 'text-slate-700' : 'text-slate-500 hover:text-slate-700'}`}
            >
                <span>{title}</span>
                <span className={`text-xs transition ${open ? 'rotate-180' : ''}`}>v</span>
            </button>
            {open && <div className="space-y-1">{children}</div>}
        </section>
    );
}

export default function AuthenticatedLayout({ header, headerActions = null, children }) {
    const page = usePage();
    const { auth } = page.props;
    const user = auth.user;
    const appTimezone = page.props?.app_timezone || 'Asia/Dubai';
    const permissions = auth.permissions || {};
    const isStaff = user?.role?.name === 'staff';
    const [now, setNow] = useState(new Date());

    useEffect(() => {
        const timer = window.setInterval(() => setNow(new Date()), 1000);

        return () => window.clearInterval(timer);
    }, []);

    const currentTimeLabel = useMemo(
        () =>
            new Intl.DateTimeFormat('en-GB', {
                hour: '2-digit',
                minute: '2-digit',
                second: '2-digit',
                hour12: true,
                timeZone: appTimezone,
            }).format(now),
        [now, appTimezone],
    );

    const canManage = useMemo(
        () =>
            permissions.can_manage_services
            || permissions.can_manage_staff
            || permissions.can_manage_schedules
            || permissions.can_manage_inventory
            || permissions.can_manage_procurement
            || permissions.can_manage_loyalty
            || permissions.can_manage_crm_automation
            || permissions.can_export_reports
            || permissions.can_run_daily_backup,
        [permissions]
    );
    const canOperate = useMemo(
        () => permissions.can_operate_frontdesk || canManage,
        [permissions, canManage]
    );

    const navGroups = useMemo(() => {
        const loyaltyPath = (page.url || '').split('?')[0];

        if (isStaff) {
            return [
                {
                    key: 'overview',
                    title: 'Overview',
                    items: [{ label: 'Dashboard', href: route('dashboard'), active: route().current('dashboard') }],
                },
                {
                    key: 'my-work',
                    title: 'My Work',
                    items: [
                        { label: 'Attendance', href: route('attendance.index'), active: route().current('attendance.*') },
                        { label: 'Leave Requests', href: route('leave-requests.index'), active: route().current('leave-requests.*') },
                        { label: 'Profile', href: route('profile.edit'), active: route().current('profile.*') },
                    ],
                },
            ];
        }

        const groups = [
            {
                key: 'overview',
                title: 'Overview',
                items: [
                    { label: 'Dashboard', href: route('dashboard'), active: route().current('dashboard') },
                    { label: 'Daily backup', href: `${route('dashboard')}#daily-backup`, active: route().current('dashboard'), visible: permissions.can_run_daily_backup },
                ],
            },
            {
                key: 'operations',
                title: 'Operations',
                items: [
                    { label: 'Customers', href: route('customers.index'), active: route().current('customers.*'), visible: canOperate },
                    { label: 'Appointments', href: route('appointments.index'), active: route().current('appointments.*'), visible: canOperate },
                    {
                        label: 'Visit checkout',
                        href: route('appointments.index', { status: 'completed' }),
                        active: route().current('appointments.*') && (page.url || '').includes('status=completed'),
                        visible: permissions.can_collect_payments && !permissions.can_manage_finance,
                    },
                    { label: 'Attendance', href: route('attendance.index'), active: route().current('attendance.*'), visible: canOperate },
                    { label: 'Leave Requests', href: route('leave-requests.index'), active: route().current('leave-requests.*'), visible: canOperate },
                ],
            },
            {
                key: 'management',
                title: 'Management',
                items: [
                    { label: 'CRM Automation', href: route('customers.automation.index'), active: route().current('customers.automation.*'), visible: permissions.can_manage_crm_automation },
                    { label: 'Services', href: route('services.index'), active: route().current('services.*'), visible: permissions.can_manage_services },
                    { label: 'Membership Registration', href: '/loyalty/membership-cards', active: ((page.url || '').split('?')[0] === '/loyalty/membership-cards'), visible: permissions.can_manage_loyalty },
                    { label: 'Packages', href: '/loyalty/packages', active: ((page.url || '').split('?')[0] === '/loyalty/packages'), visible: permissions.can_manage_loyalty },
                    { label: 'Staff', href: route('staff.index'), active: route().current('staff.*'), visible: permissions.can_manage_staff },
                    { label: 'Schedules', href: route('schedules.index'), active: route().current('schedules.*'), visible: permissions.can_manage_schedules },
                    { label: 'Inventory', href: route('inventory.index'), active: route().current('inventory.*'), visible: permissions.can_manage_inventory },
                    { label: 'Suppliers', href: route('suppliers.index'), active: route().current('suppliers.*'), visible: permissions.can_manage_procurement },
                    { label: 'Purchase Orders', href: route('purchase-orders.index'), active: route().current('purchase-orders.*'), visible: permissions.can_manage_procurement },
                ],
            },
            ...(permissions.can_manage_loyalty
                ? [
                      {
                          key: 'loyalty',
                          title: 'Loyalty',
                          items: LOYALTY_SECTIONS.map(({ id, label }) => ({
                              label,
                              href: `/loyalty/${id}`,
                              active: loyaltyPath === `/loyalty/${id}`,
                              visible: true,
                          })),
                      },
                  ]
                : []),
            {
                key: 'insights',
                title: 'Insights',
                items: [
                    { label: 'Reports', href: route('reports.index'), active: route().current('reports.*'), visible: permissions.can_export_reports },
                ],
            },
            {
                key: 'finance',
                title: 'Finance',
                items: [
                    { label: 'Finance overview', href: route('finance.index'), active: route().current('finance.index'), visible: permissions.can_manage_finance },
                    { label: 'Tax invoices', href: route('finance.invoices.index'), active: route().current('finance.invoices.*'), visible: permissions.can_manage_finance },
                    { label: 'Expenses', href: route('finance.expenses.index'), active: route().current('finance.expenses.*'), visible: permissions.can_manage_finance },
                    { label: 'Payroll', href: route('finance.payroll.index'), active: route().current('finance.payroll.*'), visible: permissions.can_manage_finance },
                    { label: 'Finance settings', href: route('finance.settings.edit'), active: route().current('finance.settings.*'), visible: permissions.can_manage_finance },
                ],
            },
            {
                key: 'access',
                title: 'Access',
                items: [
                    { label: 'Roles & Permissions', href: route('roles.index'), active: route().current('roles.*'), visible: permissions.can_manage_roles },
                ],
            },
        ];

        return groups
            .map((group) => ({
                ...group,
                items: group.items.filter((item) => item.visible !== false),
            }))
            .filter((group) => group.items.length > 0);
    }, [permissions, canOperate, isStaff, page.url]);

    const [openGroups, setOpenGroups] = useState({});

    useEffect(() => {
        setOpenGroups((prev) => {
            const next = {};
            navGroups.forEach((group) => {
                const hasActive = group.items.some((item) => item.active);
                next[group.key] = hasActive ? true : (prev[group.key] ?? true);
            });
            return next;
        });
    }, [navGroups]);

    const flatNavItems = useMemo(() => navGroups.flatMap((group) => group.items), [navGroups]);

    return (
        <div className="min-h-screen bg-slate-50 lg:flex">
            <AppFlashPopup />
            <aside className="hidden lg:fixed lg:inset-y-0 lg:flex lg:w-[280px] lg:flex-col lg:border-r lg:border-slate-200 lg:bg-slate-50">
                <div className="flex items-center gap-3 border-b border-slate-100 px-6 py-6">
                    <ApplicationLogo className="h-auto w-52" />
                </div>
                <nav className="flex-1 space-y-4 overflow-y-auto p-4">
                    {navGroups.map((group) => {
                        const groupActive = group.items.some((item) => item.active);
                        const isOpen = Boolean(openGroups[group.key]);

                        return (
                            <NavGroup
                                key={group.key}
                                title={group.title}
                                active={groupActive}
                                open={isOpen}
                                onToggle={() => setOpenGroups((prev) => ({ ...prev, [group.key]: !isOpen }))}
                            >
                                {group.items.map((item) => (
                                    <NavItem key={item.label} href={item.href} active={item.active}>
                                        {item.label}
                                    </NavItem>
                                ))}
                            </NavGroup>
                        );
                    })}
                </nav>
            </aside>

            <div className="flex-1 lg:ml-[280px]">
                <header className="sticky top-0 z-20 border-b border-slate-200 bg-white/95 backdrop-blur">
                    <div className="mx-auto flex max-w-7xl items-center justify-between px-4 py-3 sm:px-6 lg:px-8">
                        <div>
                            <p className="text-xs uppercase tracking-[0.2em] text-slate-500">Vina Management System</p>
                            <div className="text-lg font-semibold text-slate-800">{header}</div>
                        </div>
                        <div className="flex items-center gap-3">
                            {headerActions}
                            <div className="hidden rounded-lg border border-slate-200 bg-slate-50 px-2.5 py-1.5 text-right sm:block">
                                <p className="text-[10px] uppercase tracking-[0.16em] text-slate-500">Current Time</p>
                                <p className="text-xs font-semibold text-slate-700">{currentTimeLabel}</p>
                            </div>
                            <Dropdown>
                                <Dropdown.Trigger>
                                    <button className="inline-flex items-center gap-2 rounded-xl border border-slate-200 px-3 py-2 text-sm text-slate-600 hover:bg-slate-50">
                                        <span className="rounded-full bg-indigo-100 px-2 py-1 text-xs font-semibold text-indigo-600">{user?.role?.label ?? 'N/A'}</span>
                                        {user?.name}
                                    </button>
                                </Dropdown.Trigger>
                                <Dropdown.Content>
                                    <Dropdown.Link href={route('profile.edit')}>Profile</Dropdown.Link>
                                    <Dropdown.Link href={route('logout')} method="post" as="button">Log Out</Dropdown.Link>
                                </Dropdown.Content>
                            </Dropdown>
                        </div>
                    </div>
                    <div className="mx-auto border-t border-slate-100 px-4 py-2 sm:px-6 lg:hidden">
                        <nav className="flex gap-2 overflow-x-auto pb-1">
                            {flatNavItems.map((item) => (
                                <Link
                                    key={item.label}
                                    href={item.href}
                                    className={`whitespace-nowrap rounded-full border px-3 py-1.5 text-xs font-semibold transition ${item.active ? 'border-indigo-200 bg-indigo-50 text-indigo-700' : 'border-slate-200 bg-white text-slate-600 hover:bg-slate-50'}`}
                                >
                                    {item.label}
                                </Link>
                            ))}
                        </nav>
                    </div>
                </header>

                <main className="mx-auto max-w-7xl px-4 py-6 sm:px-6 lg:px-8">{children}</main>
            </div>
        </div>
    );
}
