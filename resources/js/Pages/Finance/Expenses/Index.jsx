import ConfirmActionModal from '@/Components/ConfirmActionModal';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { useEffect, useMemo, useState } from 'react';

const money = (value) =>
    new Intl.NumberFormat(undefined, { style: 'currency', currency: 'AED', minimumFractionDigits: 2 }).format(Number(value || 0));

const approvalBadgeClass = {
    pending: 'bg-amber-100 text-amber-900',
    approved: 'bg-emerald-100 text-emerald-800',
    rejected: 'bg-rose-100 text-rose-800',
};

const paymentBadgeClass = {
    paid: 'bg-emerald-100 text-emerald-800',
    unpaid: 'bg-amber-100 text-amber-900',
};

export default function FinanceExpensesIndex({
    filters,
    summary,
    breakdown,
    pettyCash,
    expenses,
    purchaseOrders,
    campaigns,
    staffProfiles,
    categories,
    costCenters,
    expenseTypes,
    expenseSubcategories,
    paymentMethods,
    approvalStatuses,
}) {
    const { flash, auth } = usePage().props;
    const [deleteExpenseId, setDeleteExpenseId] = useState(null);
    const [deleteExpenseBusy, setDeleteExpenseBusy] = useState(false);
    const [closeConfirmOpen, setCloseConfirmOpen] = useState(false);
    const [editingExpense, setEditingExpense] = useState(null);

    const typeOptions = Object.entries(expenseTypes);
    const categoryOptions = Object.entries(categories);
    const costCenterOptions = Object.entries(costCenters);
    const paymentMethodOptions = Object.entries(paymentMethods);
    const approvalStatusOptions = Object.entries(approvalStatuses);
    const subcategoryLabels = useMemo(() => Object.assign({}, ...Object.values(expenseSubcategories)), [expenseSubcategories]);

    const defaultType = typeOptions[0]?.[0] || 'operational';
    const defaultSubcategory = Object.keys(expenseSubcategories[defaultType] || {})[0] || '';

    const form = useForm({
        category: 'hospitality',
        cost_center: 'general_salon',
        expense_type: defaultType,
        expense_subcategory: defaultSubcategory,
        vendor_name: '',
        expense_date: new Date().toISOString().slice(0, 10),
        amount_subtotal: '',
        vat_amount: '0',
        payment_status: 'paid',
        payment_method: 'cash',
        paid_at: '',
        receipt_number: '',
        receipt_image: null,
        remove_receipt_image: false,
        purchase_order_id: '',
        campaign_id: '',
        staff_profile_id: '',
        notes: '',
    });

    const pettyCashIssueForm = useForm({
        staff_profile_id: '',
        amount: '',
        transaction_date: new Date().toISOString().slice(0, 10),
        notes: '',
    });

    const pettyCashCloseForm = useForm({
        staff_profile_id: '',
        closing_date: new Date().toISOString().slice(0, 10),
        counted_closing_balance: '',
        signed_off_name: auth?.user?.name || '',
        notes: '',
    });

    const activeSubcategories = useMemo(
        () => Object.entries(expenseSubcategories[form.data.expense_type] || {}),
        [expenseSubcategories, form.data.expense_type],
    );

    useEffect(() => {
        if (!activeSubcategories.find(([value]) => value === form.data.expense_subcategory)) {
            form.setData('expense_subcategory', activeSubcategories[0]?.[0] || '');
        }
    }, [activeSubcategories, form]);

    const applyFilter = (key, value) => {
        router.get(route('finance.expenses.index'), { ...filters, [key]: value }, { preserveState: true, replace: true });
    };

    const pettyCashReportParams = new URLSearchParams({
        date_from: filters.date_from,
        date_to: filters.date_to,
        ...(filters.staff_profile_id ? { staff_profile_id: filters.staff_profile_id } : {}),
    }).toString();

    const selectedClosingBalance = useMemo(
        () =>
            pettyCash.balances.find((row) =>
                pettyCashCloseForm.data.staff_profile_id === ''
                    ? row.staff_profile_id == null
                    : String(row.staff_profile_id) === pettyCashCloseForm.data.staff_profile_id,
            ) || null,
        [pettyCash.balances, pettyCashCloseForm.data.staff_profile_id],
    );

    const expectedClosingBalance = Number(selectedClosingBalance?.balance || 0);
    const countedClosingBalance = Number(pettyCashCloseForm.data.counted_closing_balance || 0);
    const closingVariance = countedClosingBalance - expectedClosingBalance;
    const selectedClosingCustodian = selectedClosingBalance?.custodian || 'General fund';

    const selectedCustodianSnapshot = useMemo(() => {
        const selectedId = pettyCashCloseForm.data.staff_profile_id;
        const sameCustodian = (row) =>
            selectedId === '' ? row.staff_profile_id == null : String(row.staff_profile_id) === selectedId;

        return pettyCash.recent_entries.reduce(
            (totals, row) => {
                if (!sameCustodian(row)) {
                    return totals;
                }

                const amount = Number(row.amount || 0);

                if (row.direction === 'in') {
                    totals.issued += amount;
                } else {
                    totals.deducted += amount;
                }

                return totals;
            },
            {
                issued: 0,
                deducted: 0,
                remaining: expectedClosingBalance,
            },
        );
    }, [expectedClosingBalance, pettyCash.recent_entries, pettyCashCloseForm.data.staff_profile_id]);

    const submitPettyCashClose = () => {
        pettyCashCloseForm.post(route('finance.expenses.petty-cash.close'), {
            onSuccess: () => {
                pettyCashCloseForm.reset('counted_closing_balance', 'notes');
                setCloseConfirmOpen(false);
            },
            onError: () => setCloseConfirmOpen(false),
        });
    };

    const startEdit = (row) => {
        setEditingExpense(row);
        form.setData({
            category: row.category || 'hospitality',
            cost_center: row.cost_center || 'general_salon',
            expense_type: row.expense_type || defaultType,
            expense_subcategory: row.expense_subcategory || defaultSubcategory,
            vendor_name: row.vendor_name || '',
            expense_date: row.expense_date || new Date().toISOString().slice(0, 10),
            amount_subtotal: String(row.amount_subtotal ?? ''),
            vat_amount: String(row.vat_amount ?? 0),
            payment_status: row.payment_status || 'paid',
            payment_method: row.payment_method || 'cash',
            paid_at: row.paid_at ? row.paid_at.slice(0, 16) : '',
            receipt_number: row.receipt_number || '',
            receipt_image: null,
            remove_receipt_image: false,
            purchase_order_id: row.purchase_order?.id ? String(row.purchase_order.id) : '',
            campaign_id: row.campaign?.id ? String(row.campaign.id) : '',
            staff_profile_id: row.staff_profile?.id ? String(row.staff_profile.id) : '',
            notes: row.notes || '',
        });
    };

    const resetExpenseForm = () => {
        setEditingExpense(null);
        form.setData({
            category: 'hospitality',
            cost_center: 'general_salon',
            expense_type: defaultType,
            expense_subcategory: defaultSubcategory,
            vendor_name: '',
            expense_date: new Date().toISOString().slice(0, 10),
            amount_subtotal: '',
            vat_amount: '0',
            payment_status: 'paid',
            payment_method: 'cash',
            paid_at: '',
            receipt_number: '',
            receipt_image: null,
            remove_receipt_image: false,
            purchase_order_id: '',
            campaign_id: '',
            staff_profile_id: '',
            notes: '',
        });
    };

    return (
        <AuthenticatedLayout header="Expenses">
            <Head title="Expenses" />

            <div className="space-y-6">
                {flash?.status && <div className="ta-card border-emerald-200 bg-emerald-50 p-3 text-sm text-emerald-700">{flash.status}</div>}
                {Object.keys(form.errors || {}).length > 0 && (
                    <div className="ta-card border-rose-200 bg-rose-50 p-3 text-sm text-rose-700">
                        {Object.values(form.errors)[0]}
                    </div>
                )}

                <div className="flex flex-wrap gap-3">
                    <Link href={route('finance.index')} className="text-sm text-indigo-600 hover:underline">
                        {'<-'} Finance overview
                    </Link>
                </div>

                <section className="grid gap-4 md:grid-cols-2 xl:grid-cols-5">
                    <div className="ta-card p-4">
                        <p className="text-xs uppercase tracking-wide text-slate-500">Period total</p>
                        <p className="mt-1 text-2xl font-semibold text-slate-900">{money(summary.period_total)}</p>
                    </div>
                    <div className="ta-card p-4">
                        <p className="text-xs uppercase tracking-wide text-slate-500">Today</p>
                        <p className="mt-1 text-2xl font-semibold text-indigo-700">{money(summary.today_total)}</p>
                    </div>
                    <div className="ta-card p-4">
                        <p className="text-xs uppercase tracking-wide text-slate-500">Pending approval</p>
                        <p className="mt-1 text-2xl font-semibold text-amber-700">{money(summary.pending_approval_total)}</p>
                    </div>
                    <div className="ta-card p-4">
                        <p className="text-xs uppercase tracking-wide text-slate-500">Approved</p>
                        <p className="mt-1 text-2xl font-semibold text-emerald-700">{money(summary.approved_total)}</p>
                    </div>
                    <div className="ta-card p-4">
                        <p className="text-xs uppercase tracking-wide text-slate-500">Paid total</p>
                        <p className="mt-1 text-2xl font-semibold text-slate-900">{money(summary.cash_total)}</p>
                    </div>
                </section>

                <section className="ta-card p-5">
                    <div className="mb-5 flex flex-col gap-4 xl:flex-row xl:items-start xl:justify-between">
                        <div className="max-w-2xl">
                            <h3 className="text-base font-semibold text-slate-800">Petty cash workspace</h3>
                            <p className="mt-1 text-sm text-slate-500">
                                Manage the full day here: issue cash, review live custodian balances, check recent movements, then close and sign off with the final counted amount.
                            </p>
                        </div>
                        <div className="flex flex-col gap-3 sm:flex-row sm:flex-wrap sm:items-stretch xl:max-w-xl xl:justify-end">
                            <div className="rounded-2xl bg-indigo-50 px-5 py-4 sm:min-w-[220px]">
                                <p className="text-[11px] uppercase tracking-[0.18em] text-indigo-600">Total balance</p>
                                <p className="mt-2 text-3xl font-semibold text-indigo-950">{money(pettyCash.total_balance)}</p>
                                <p className="mt-1 text-xs text-indigo-700">Available across general fund and all custodians.</p>
                            </div>
                            <div className="grid gap-2 sm:min-w-[220px]">
                                <a href={`${route('finance.expenses.petty-cash.export')}?${pettyCashReportParams}`} className="rounded-xl border border-slate-200 bg-white px-4 py-2.5 text-center text-sm font-medium text-slate-700">
                                    Export closing CSV
                                </a>
                                <a href={`${route('finance.expenses.petty-cash.pdf')}?${pettyCashReportParams}`} className="rounded-xl border border-slate-200 bg-white px-4 py-2.5 text-center text-sm font-medium text-slate-700">
                                    Download PDF
                                </a>
                                <a href={`${route('finance.expenses.petty-cash.print')}?${pettyCashReportParams}`} target="_blank" rel="noreferrer" className="rounded-xl border border-slate-200 bg-white px-4 py-2.5 text-center text-sm font-medium text-slate-700">
                                    Print closing
                                </a>
                            </div>
                        </div>
                    </div>

                    <div className="mb-5 grid gap-3 md:grid-cols-2 xl:grid-cols-4">
                        <div className="rounded-3xl border border-slate-200 bg-slate-50 px-4 py-4">
                            <p className="text-[11px] uppercase tracking-[0.16em] text-slate-500">Selected custodian</p>
                            <p className="mt-2 text-lg font-semibold text-slate-900">{selectedClosingCustodian}</p>
                            <p className="mt-1 text-xs text-slate-500">Snapshot uses the custodian selected in the closing panel.</p>
                        </div>
                        <div className="rounded-3xl border border-emerald-200 bg-emerald-50 px-4 py-4">
                            <p className="text-[11px] uppercase tracking-[0.16em] text-emerald-700">Issued today</p>
                            <p className="mt-2 text-2xl font-semibold text-emerald-900">{money(selectedCustodianSnapshot.issued)}</p>
                        </div>
                        <div className="rounded-3xl border border-amber-200 bg-amber-50 px-4 py-4">
                            <p className="text-[11px] uppercase tracking-[0.16em] text-amber-700">Deducted today</p>
                            <p className="mt-2 text-2xl font-semibold text-amber-900">{money(selectedCustodianSnapshot.deducted)}</p>
                        </div>
                        <div className="rounded-3xl border border-indigo-200 bg-indigo-50 px-4 py-4">
                            <p className="text-[11px] uppercase tracking-[0.16em] text-indigo-700">Remaining now</p>
                            <p className="mt-2 text-2xl font-semibold text-indigo-950">{money(selectedCustodianSnapshot.remaining)}</p>
                        </div>
                    </div>

                    <div className="grid gap-5 xl:grid-cols-2 2xl:grid-cols-12">
                        <form
                            className="min-w-0 space-y-4 rounded-3xl border border-slate-200 bg-gradient-to-br from-slate-50 to-white p-5 shadow-sm 2xl:col-span-4"
                            onSubmit={(e) => {
                                e.preventDefault();
                                pettyCashIssueForm.post(route('finance.expenses.petty-cash.issue'), {
                                    onSuccess: () => pettyCashIssueForm.reset('staff_profile_id', 'amount', 'notes'),
                                });
                            }}
                        >
                            <div>
                                <span className="inline-flex rounded-full bg-slate-900 px-2.5 py-1 text-[11px] font-semibold uppercase tracking-[0.16em] text-white">Funding</span>
                                <h4 className="mt-3 text-base font-semibold text-slate-800">1. Issue petty cash</h4>
                                <p className="mt-1 text-xs text-slate-500">Create an opening balance, top-up, or event float for a staff custodian or the general fund.</p>
                            </div>
                            <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-1 2xl:grid-cols-2">
                                <div>
                                    <label className="ta-field-label">Custodian</label>
                                    <select className="ta-input" value={pettyCashIssueForm.data.staff_profile_id} onChange={(e) => pettyCashIssueForm.setData('staff_profile_id', e.target.value)}>
                                        <option value="">General fund</option>
                                        {staffProfiles.map((staff) => (
                                            <option key={staff.id} value={staff.id}>
                                                {staff.label}
                                            </option>
                                        ))}
                                    </select>
                                </div>
                                <div>
                                    <label className="ta-field-label">Amount</label>
                                    <input type="number" step="0.01" min="0.01" className="ta-input" value={pettyCashIssueForm.data.amount} onChange={(e) => pettyCashIssueForm.setData('amount', e.target.value)} required />
                                </div>
                                <div>
                                    <label className="ta-field-label">Date</label>
                                    <input type="date" className="ta-input" value={pettyCashIssueForm.data.transaction_date} onChange={(e) => pettyCashIssueForm.setData('transaction_date', e.target.value)} required />
                                </div>
                                <div>
                                    <label className="ta-field-label">Notes</label>
                                    <input className="ta-input" value={pettyCashIssueForm.data.notes} onChange={(e) => pettyCashIssueForm.setData('notes', e.target.value)} placeholder="Opening cash, top-up, event float..." />
                                </div>
                            </div>
                            <button type="submit" className="ta-btn-primary" disabled={pettyCashIssueForm.processing}>
                                Issue cash
                            </button>
                        </form>

                        <div className="min-w-0 grid gap-5 2xl:col-span-4">
                            <div className="min-w-0 rounded-3xl border border-slate-200 bg-white p-5 shadow-sm">
                                <div className="mb-3">
                                    <span className="inline-flex rounded-full bg-indigo-100 px-2.5 py-1 text-[11px] font-semibold uppercase tracking-[0.16em] text-indigo-700">Live balance</span>
                                    <h4 className="text-base font-semibold text-slate-800">2. Balances by custodian</h4>
                                    <p className="mt-1 text-xs text-slate-500">This should match what each person is holding before you close the day.</p>
                                </div>
                                <div className="space-y-2">
                                    {pettyCash.balances.map((row) => (
                                        <div key={`${row.custodian}-${row.staff_profile_id ?? 'general'}`} className="flex items-center justify-between rounded-xl border border-slate-100 px-3 py-3 text-sm">
                                            <span className="font-medium text-slate-700">{row.custodian}</span>
                                            <span className={`text-base font-semibold ${row.balance < 0 ? 'text-rose-700' : 'text-slate-900'}`}>{money(row.balance)}</span>
                                        </div>
                                    ))}
                                    {pettyCash.balances.length === 0 && <p className="text-sm text-slate-500">No petty-cash balances yet.</p>}
                                </div>
                            </div>

                            <div className="min-w-0 rounded-3xl border border-slate-200 bg-white p-5 shadow-sm">
                                <div className="mb-3">
                                    <span className="inline-flex rounded-full bg-amber-100 px-2.5 py-1 text-[11px] font-semibold uppercase tracking-[0.16em] text-amber-700">Review</span>
                                    <h4 className="text-base font-semibold text-slate-800">3. Recent movements</h4>
                                    <p className="mt-1 text-xs text-slate-500">Quick review of the latest issues, deductions, and adjustments before sign-off.</p>
                                </div>
                                <div className="min-w-0 overflow-hidden rounded-2xl border border-slate-100">
                                    <div className="overflow-x-auto">
                                        <table className="min-w-full text-sm">
                                            <thead className="bg-slate-50 text-left text-[11px] uppercase tracking-[0.14em] text-slate-500">
                                                <tr>
                                                    <th className="px-3 py-2">Date</th>
                                                    <th className="px-3 py-2">Type</th>
                                                    <th className="px-3 py-2">Custodian</th>
                                                    <th className="px-3 py-2 text-right">Amount</th>
                                                    <th className="px-3 py-2">Notes</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                {pettyCash.recent_entries.map((row) => (
                                                    <tr key={row.id} className="border-t border-slate-100 align-top">
                                                        <td className="px-3 py-2 text-slate-600">{row.transaction_date}</td>
                                                        <td className="px-3 py-2">
                                                            <span className={`inline-flex rounded-full px-2 py-1 text-[11px] font-semibold ${row.direction === 'in' ? 'bg-emerald-100 text-emerald-800' : 'bg-rose-100 text-rose-800'}`}>
                                                                {row.transaction_type === 'issue' ? 'Issue' : 'Expense'}
                                                            </span>
                                                        </td>
                                                        <td className="px-3 py-2 text-slate-700">{row.custodian}</td>
                                                        <td className={`px-3 py-2 text-right font-semibold ${row.direction === 'in' ? 'text-emerald-700' : 'text-rose-700'}`}>
                                                            {row.direction === 'in' ? '+' : '-'}{money(row.amount)}
                                                        </td>
                                                        <td className="px-3 py-2 text-xs text-slate-500">
                                                            {row.expense_subcategory ? `${subcategoryLabels[row.expense_subcategory] || row.expense_subcategory} · ` : ''}
                                                            {row.notes || '-'}
                                                        </td>
                                                    </tr>
                                                ))}
                                            </tbody>
                                        </table>
                                    </div>
                                    {pettyCash.recent_entries.length === 0 && <p className="p-3 text-sm text-slate-500">No petty-cash movements recorded yet.</p>}
                                </div>
                            </div>
                        </div>

                        <form
                            className="min-w-0 space-y-4 rounded-3xl border border-rose-200 bg-gradient-to-br from-rose-50 to-white p-5 shadow-sm xl:col-span-2 2xl:col-span-4"
                            onSubmit={(e) => {
                                e.preventDefault();
                                setCloseConfirmOpen(true);
                            }}
                        >
                            <div>
                                <span className="inline-flex rounded-full bg-rose-600 px-2.5 py-1 text-[11px] font-semibold uppercase tracking-[0.16em] text-white">Closing</span>
                                <h4 className="mt-3 text-base font-semibold text-slate-800">4. Close & sign off</h4>
                                <p className="mt-1 text-xs text-slate-500">Enter the counted cash, record any variance, and lock that day from further petty-cash changes.</p>
                            </div>
                            <div className="grid gap-3 sm:grid-cols-3">
                                <div className="rounded-2xl border border-slate-200 bg-white px-4 py-3">
                                    <p className="text-[11px] uppercase tracking-[0.16em] text-slate-500">Expected</p>
                                    <p className="mt-1 text-lg font-semibold text-slate-900">{money(expectedClosingBalance)}</p>
                                </div>
                                <div className="rounded-2xl border border-slate-200 bg-white px-4 py-3">
                                    <p className="text-[11px] uppercase tracking-[0.16em] text-slate-500">Counted</p>
                                    <p className="mt-1 text-lg font-semibold text-slate-900">{money(countedClosingBalance)}</p>
                                </div>
                                <div className={`rounded-2xl border px-4 py-3 ${closingVariance === 0 ? 'border-emerald-200 bg-emerald-50' : closingVariance > 0 ? 'border-amber-200 bg-amber-50' : 'border-rose-200 bg-rose-50'}`}>
                                    <p className="text-[11px] uppercase tracking-[0.16em] text-slate-500">Variance</p>
                                    <p className={`mt-1 text-lg font-semibold ${closingVariance === 0 ? 'text-emerald-700' : closingVariance > 0 ? 'text-amber-700' : 'text-rose-700'}`}>
                                        {closingVariance > 0 ? '+' : ''}{money(closingVariance)}
                                    </p>
                                </div>
                            </div>
                            <div className={`rounded-2xl border px-4 py-3 text-sm ${closingVariance === 0 ? 'border-emerald-200 bg-emerald-50 text-emerald-800' : closingVariance > 0 ? 'border-amber-200 bg-amber-50 text-amber-800' : 'border-rose-200 bg-rose-50 text-rose-800'}`}>
                                {closingVariance === 0
                                    ? 'Counted cash matches the expected balance for this custodian.'
                                    : closingVariance > 0
                                      ? 'Counted cash is higher than expected. This will create a positive variance adjustment.'
                                      : 'Counted cash is lower than expected. This will create a shortage variance adjustment.'}
                            </div>
                            <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-1 2xl:grid-cols-2">
                                <div>
                                    <label className="ta-field-label">Custodian</label>
                                    <select className="ta-input" value={pettyCashCloseForm.data.staff_profile_id} onChange={(e) => pettyCashCloseForm.setData('staff_profile_id', e.target.value)}>
                                        <option value="">General fund</option>
                                        {staffProfiles.map((staff) => (
                                            <option key={staff.id} value={staff.id}>
                                                {staff.label}
                                            </option>
                                        ))}
                                    </select>
                                </div>
                                <div>
                                    <label className="ta-field-label">Closing date</label>
                                    <input type="date" className="ta-input" value={pettyCashCloseForm.data.closing_date} onChange={(e) => pettyCashCloseForm.setData('closing_date', e.target.value)} required />
                                </div>
                                <div>
                                    <label className="ta-field-label">Counted cash</label>
                                    <input type="number" step="0.01" min="0" className="ta-input" value={pettyCashCloseForm.data.counted_closing_balance} onChange={(e) => pettyCashCloseForm.setData('counted_closing_balance', e.target.value)} required />
                                </div>
                                <div>
                                    <label className="ta-field-label">Signed off by</label>
                                    <input className="ta-input" value={pettyCashCloseForm.data.signed_off_name} onChange={(e) => pettyCashCloseForm.setData('signed_off_name', e.target.value)} />
                                </div>
                                <div className="sm:col-span-2 xl:col-span-1 2xl:col-span-2">
                                    <label className="ta-field-label">Notes</label>
                                    <input className="ta-input" value={pettyCashCloseForm.data.notes} onChange={(e) => pettyCashCloseForm.setData('notes', e.target.value)} placeholder="Variance explanation or closing comments" />
                                </div>
                            </div>
                            <button type="submit" className="ta-btn-primary" disabled={pettyCashCloseForm.processing}>
                                Review and close day
                            </button>
                        </form>
                    </div>
                </section>

                <section className="ta-card p-5">
                    <div className="mb-4 flex flex-col gap-3 lg:flex-row lg:items-end lg:justify-between">
                        <div>
                            <h3 className="text-sm font-semibold text-slate-700">Filters & review</h3>
                            <p className="mt-1 text-xs text-slate-500">Focus on pending staff expenses, petty cash, or reimbursable items before they affect reporting.</p>
                        </div>
                        <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-6">
                            <div>
                                <label className="ta-field-label">From</label>
                                <input type="date" className="ta-input" value={filters.date_from} onChange={(e) => applyFilter('date_from', e.target.value)} />
                            </div>
                            <div>
                                <label className="ta-field-label">To</label>
                                <input type="date" className="ta-input" value={filters.date_to} onChange={(e) => applyFilter('date_to', e.target.value)} />
                            </div>
                            <div>
                                <label className="ta-field-label">Type</label>
                                <select className="ta-input" value={filters.expense_type} onChange={(e) => applyFilter('expense_type', e.target.value)}>
                                    <option value="">All</option>
                                    {typeOptions.map(([value, label]) => (
                                        <option key={value} value={value}>
                                            {label}
                                        </option>
                                    ))}
                                </select>
                            </div>
                            <div>
                                <label className="ta-field-label">Approval</label>
                                <select className="ta-input" value={filters.approval_status} onChange={(e) => applyFilter('approval_status', e.target.value)}>
                                    <option value="all">All</option>
                                    {approvalStatusOptions.map(([value, label]) => (
                                        <option key={value} value={value}>
                                            {label}
                                        </option>
                                    ))}
                                </select>
                            </div>
                            <div>
                                <label className="ta-field-label">Payment</label>
                                <select className="ta-input" value={filters.payment_status} onChange={(e) => applyFilter('payment_status', e.target.value)}>
                                    <option value="all">All</option>
                                    <option value="paid">Paid</option>
                                    <option value="unpaid">Unpaid</option>
                                </select>
                            </div>
                            <div>
                                <label className="ta-field-label">Staff</label>
                                <select className="ta-input" value={filters.staff_profile_id} onChange={(e) => applyFilter('staff_profile_id', e.target.value)}>
                                    <option value="">All staff</option>
                                    {staffProfiles.map((staff) => (
                                        <option key={staff.id} value={staff.id}>
                                            {staff.label}
                                        </option>
                                    ))}
                                </select>
                            </div>
                        </div>
                    </div>
                    <div>
                        <label className="ta-field-label">Search</label>
                        <input className="ta-input" value={filters.search} onChange={(e) => applyFilter('search', e.target.value)} placeholder="Vendor, receipt number, notes, or subcategory" />
                    </div>
                </section>

                <section className="grid gap-6 lg:grid-cols-3">
                    <div className="ta-card p-5">
                        <h3 className="mb-3 text-sm font-semibold text-slate-700">By type</h3>
                        <div className="space-y-2">
                            {breakdown.byType.map((row) => (
                                <div key={row.key}>
                                    <div className="mb-1 flex justify-between text-xs text-slate-600">
                                        <span>{expenseTypes[row.key] || row.key}</span>
                                        <span>{money(row.total)} | {row.count}</span>
                                    </div>
                                    <div className="h-2 rounded-full bg-slate-100">
                                        <div className="h-2 rounded-full bg-indigo-500" style={{ width: `${summary.period_total > 0 ? (row.total / summary.period_total) * 100 : 0}%` }} />
                                    </div>
                                </div>
                            ))}
                            {breakdown.byType.length === 0 && <p className="text-sm text-slate-500">No expenses in this range.</p>}
                        </div>
                    </div>
                    <div className="ta-card p-5">
                        <h3 className="mb-3 text-sm font-semibold text-slate-700">By category</h3>
                        <div className="space-y-2">
                            {breakdown.byCategory.map((row) => (
                                <div key={row.key} className="flex justify-between border-b border-slate-100 py-1 text-sm">
                                    <span className="text-slate-600">{categories[row.key] || row.key}</span>
                                    <span className="font-medium text-slate-900">{money(row.total)}</span>
                                </div>
                            ))}
                            {breakdown.byCategory.length === 0 && <p className="text-sm text-slate-500">No categories to show.</p>}
                        </div>
                    </div>
                    <div className="ta-card p-5">
                        <h3 className="mb-3 text-sm font-semibold text-slate-700">Last 7 days</h3>
                        <div className="space-y-2">
                            {breakdown.daily.map((row) => (
                                <div key={row.date} className="flex justify-between border-b border-slate-100 py-1 text-sm">
                                    <span className="text-slate-600">{row.date}</span>
                                    <span className="font-medium text-slate-900">{money(row.total)} | {row.count}</span>
                                </div>
                            ))}
                            {breakdown.daily.length === 0 && <p className="text-sm text-slate-500">No daily activity yet.</p>}
                        </div>
                    </div>
                </section>

                <section className="ta-card p-5">
                    <div className="mb-4 flex flex-wrap items-start justify-between gap-3">
                        <div>
                            <h3 className="text-sm font-semibold text-slate-700">{editingExpense ? 'Edit expense' : 'Record expense'}</h3>
                            <p className="mt-1 text-xs text-slate-500">New or edited expenses return to pending approval. Approved petty-cash expenses deduct from the selected custodian balance.</p>
                        </div>
                        <div className="flex flex-wrap gap-2 text-xs">
                            <button type="button" className="rounded-full bg-amber-50 px-3 py-1 text-amber-900" onClick={() => {
                                form.setData('expense_type', 'staff_welfare');
                                form.setData('category', 'hospitality');
                            }}>
                                Staff welfare
                            </button>
                            <button type="button" className="rounded-full bg-slate-100 px-3 py-1 text-slate-700" onClick={() => {
                                form.setData('expense_type', 'petty_cash');
                                form.setData('category', 'miscellaneous');
                                form.setData('payment_method', 'cash');
                            }}>
                                Petty cash
                            </button>
                            <button type="button" className="rounded-full bg-slate-100 px-3 py-1 text-slate-700" onClick={() => {
                                form.setData('expense_type', 'staff_reimbursement');
                                form.setData('category', 'administration_finance');
                            }}>
                                Reimbursement
                            </button>
                            {editingExpense && (
                                <button type="button" className="rounded-full bg-slate-200 px-3 py-1 text-slate-700" onClick={resetExpenseForm}>
                                    Cancel edit
                                </button>
                            )}
                        </div>
                    </div>
                    <form
                        onSubmit={(e) => {
                            e.preventDefault();
                            form.transform((d) => ({
                                ...d,
                                amount_subtotal: parseFloat(d.amount_subtotal),
                                vat_amount: parseFloat(d.vat_amount),
                                purchase_order_id: d.purchase_order_id || null,
                                campaign_id: d.campaign_id || null,
                                staff_profile_id: d.staff_profile_id || null,
                                _method: editingExpense ? 'put' : undefined,
                            }));
                            form.post(editingExpense ? route('finance.expenses.update', editingExpense.id) : route('finance.expenses.store'), {
                                forceFormData: true,
                                onSuccess: () => resetExpenseForm(),
                            });
                        }}
                        className="grid gap-3 md:grid-cols-3"
                    >
                        <div>
                            <label className="ta-field-label">Expense type</label>
                            <select className="ta-input" value={form.data.expense_type} onChange={(e) => form.setData('expense_type', e.target.value)}>
                                {typeOptions.map(([value, label]) => (
                                    <option key={value} value={value}>
                                        {label}
                                    </option>
                                ))}
                            </select>
                        </div>
                        <div>
                            <label className="ta-field-label">Subcategory</label>
                            <select className="ta-input" value={form.data.expense_subcategory} onChange={(e) => form.setData('expense_subcategory', e.target.value)}>
                                {activeSubcategories.map(([value, label]) => (
                                    <option key={value} value={value}>
                                        {label}
                                    </option>
                                ))}
                            </select>
                        </div>
                        <div>
                            <label className="ta-field-label">Accounting category</label>
                            <select className="ta-input" value={form.data.category} onChange={(e) => form.setData('category', e.target.value)}>
                                {categoryOptions.map(([value, label]) => (
                                    <option key={value} value={value}>
                                        {label}
                                    </option>
                                ))}
                            </select>
                        </div>
                        <div>
                            <label className="ta-field-label">Cost center</label>
                            <select className="ta-input" value={form.data.cost_center} onChange={(e) => form.setData('cost_center', e.target.value)}>
                                {costCenterOptions.map(([value, label]) => (
                                    <option key={value} value={value}>
                                        {label}
                                    </option>
                                ))}
                            </select>
                        </div>
                        <div>
                            <label className="ta-field-label">Vendor / paid to</label>
                            <input className="ta-input" value={form.data.vendor_name} onChange={(e) => form.setData('vendor_name', e.target.value)} placeholder="Restaurant, market, supplier..." />
                        </div>
                        <div>
                            <label className="ta-field-label">{form.data.expense_type === 'petty_cash' ? 'Petty-cash custodian' : 'Staff member'}</label>
                            <select className="ta-input" value={form.data.staff_profile_id} onChange={(e) => form.setData('staff_profile_id', e.target.value)}>
                                <option value="">{form.data.expense_type === 'petty_cash' ? 'General fund' : 'Not staff-specific'}</option>
                                {staffProfiles.map((staff) => (
                                    <option key={staff.id} value={staff.id}>
                                        {staff.label}
                                    </option>
                                ))}
                            </select>
                        </div>
                        <div>
                            <label className="ta-field-label">Date</label>
                            <input type="date" className="ta-input" value={form.data.expense_date} onChange={(e) => form.setData('expense_date', e.target.value)} required />
                        </div>
                        <div>
                            <label className="ta-field-label">Amount (excl. VAT)</label>
                            <input type="number" step="0.01" min="0" className="ta-input" value={form.data.amount_subtotal} onChange={(e) => form.setData('amount_subtotal', e.target.value)} required />
                        </div>
                        <div>
                            <label className="ta-field-label">VAT amount</label>
                            <input type="number" step="0.01" min="0" className="ta-input" value={form.data.vat_amount} onChange={(e) => form.setData('vat_amount', e.target.value)} required />
                        </div>
                        <div>
                            <label className="ta-field-label">Payment status</label>
                            <select className="ta-input" value={form.data.payment_status} onChange={(e) => form.setData('payment_status', e.target.value)}>
                                <option value="unpaid">Unpaid (payable)</option>
                                <option value="paid">Paid</option>
                            </select>
                        </div>
                        <div>
                            <label className="ta-field-label">Payment method</label>
                            <select className="ta-input" value={form.data.payment_method} onChange={(e) => form.setData('payment_method', e.target.value)}>
                                {paymentMethodOptions.map(([value, label]) => (
                                    <option key={value} value={value}>
                                        {label}
                                    </option>
                                ))}
                            </select>
                        </div>
                        <div>
                            <label className="ta-field-label">Receipt number</label>
                            <input className="ta-input" value={form.data.receipt_number} onChange={(e) => form.setData('receipt_number', e.target.value)} placeholder="Optional receipt ref" />
                        </div>
                        <div>
                            <label className="ta-field-label">Link PO (optional)</label>
                            <select className="ta-input" value={form.data.purchase_order_id} onChange={(e) => form.setData('purchase_order_id', e.target.value)}>
                                <option value="">None</option>
                                {purchaseOrders.map((po) => (
                                    <option key={po.id} value={po.id}>
                                        {po.label}
                                    </option>
                                ))}
                            </select>
                        </div>
                        <div>
                            <label className="ta-field-label">Campaign (optional)</label>
                            <select className="ta-input" value={form.data.campaign_id} onChange={(e) => form.setData('campaign_id', e.target.value)}>
                                <option value="">None</option>
                                {campaigns.map((campaign) => (
                                    <option key={campaign.id} value={campaign.id}>
                                        {campaign.label}
                                    </option>
                                ))}
                            </select>
                        </div>
                        <div className="md:col-span-2">
                            <label className="ta-field-label">Receipt image</label>
                            <input type="file" accept="image/*" className="ta-input" onChange={(e) => form.setData('receipt_image', e.target.files?.[0] || null)} />
                            {editingExpense?.receipt_image_url && (
                                <label className="mt-2 flex items-center gap-2 text-xs text-slate-600">
                                    <input type="checkbox" checked={form.data.remove_receipt_image} onChange={(e) => form.setData('remove_receipt_image', e.target.checked)} />
                                    Remove current receipt image
                                </label>
                            )}
                        </div>
                        <div className="md:col-span-3">
                            <label className="ta-field-label">Notes</label>
                            <input className="ta-input" value={form.data.notes} onChange={(e) => form.setData('notes', e.target.value)} placeholder="Why this was spent, who requested it, or any context..." />
                        </div>
                        <button type="submit" className="ta-btn-primary" disabled={form.processing}>
                            {editingExpense ? 'Update expense' : 'Save expense'}
                        </button>
                    </form>
                </section>

                <section className="ta-card overflow-hidden">
                    <div className="border-b border-slate-200 px-5 py-4">
                        <h3 className="text-sm font-semibold text-slate-700">Expense ledger</h3>
                    </div>
                    <div className="overflow-x-auto">
                        <table className="min-w-full text-sm">
                            <thead className="bg-slate-50 text-left text-xs uppercase text-slate-500">
                                <tr>
                                    <th className="px-5 py-3">Date</th>
                                    <th className="px-5 py-3">Type</th>
                                    <th className="px-5 py-3">Details</th>
                                    <th className="px-5 py-3">Staff</th>
                                    <th className="px-5 py-3 text-right">Total</th>
                                    <th className="px-5 py-3">Approval</th>
                                    <th className="px-5 py-3">Payment</th>
                                    <th className="px-5 py-3">Receipt</th>
                                    <th className="px-5 py-3" />
                                </tr>
                            </thead>
                            <tbody>
                                {expenses.data.map((row) => (
                                    <tr key={row.id} className="border-t border-slate-100 align-top">
                                        <td className="px-5 py-3">{row.expense_date}</td>
                                        <td className="px-5 py-3">
                                            <div className="font-medium text-slate-800">{expenseTypes[row.expense_type] || row.expense_type}</div>
                                            <div className="text-xs text-slate-500">{categories[row.category] || row.category}</div>
                                            <div className="text-xs text-slate-500">{costCenters[row.cost_center] || row.cost_center}</div>
                                        </td>
                                        <td className="px-5 py-3 text-slate-600">
                                            <div>{subcategoryLabels[row.expense_subcategory] || row.expense_subcategory || '-'}</div>
                                            <div className="text-xs text-slate-500">{row.vendor_name || 'No vendor'}</div>
                                            {row.campaign?.name && <div className="text-xs text-indigo-600">Campaign: {row.campaign.name}</div>}
                                            {row.notes && <div className="mt-1 text-xs text-slate-500">{row.notes}</div>}
                                        </td>
                                        <td className="px-5 py-3 text-slate-600">{row.staff_profile?.name || '-'}</td>
                                        <td className="px-5 py-3 text-right">
                                            <div className="font-medium">{money(row.total_amount)}</div>
                                            <div className="text-xs text-slate-500">{money(row.amount_subtotal)} + VAT {money(row.vat_amount)}</div>
                                        </td>
                                        <td className="px-5 py-3">
                                            <span className={`rounded-full px-2 py-0.5 text-xs font-semibold ${approvalBadgeClass[row.approval_status] || 'bg-slate-100 text-slate-700'}`}>
                                                {approvalStatuses[row.approval_status] || row.approval_status}
                                            </span>
                                            {row.approved_by_name && <div className="mt-1 text-xs text-slate-500">{row.approved_by_name}</div>}
                                        </td>
                                        <td className="px-5 py-3">
                                            <span className={`rounded-full px-2 py-0.5 text-xs font-semibold ${paymentBadgeClass[row.payment_status] || 'bg-slate-100 text-slate-700'}`}>
                                                {row.payment_status}
                                            </span>
                                            <div className="mt-1 text-xs text-slate-500">{paymentMethods[row.payment_method] || row.payment_method}</div>
                                        </td>
                                        <td className="px-5 py-3 text-xs text-slate-600">
                                            {row.receipt_image_url ? <a href={row.receipt_image_url} target="_blank" rel="noreferrer" className="text-indigo-600 hover:underline">View receipt</a> : '-'}
                                            {row.receipt_number && <div className="mt-1 text-slate-500">{row.receipt_number}</div>}
                                        </td>
                                        <td className="px-5 py-3">
                                            <div className="flex flex-wrap gap-2">
                                                {row.approval_status === 'pending' && (
                                                    <>
                                                        <button type="button" className="text-xs text-emerald-700 hover:underline" onClick={() => router.patch(route('finance.expenses.approval.update', row.id), { approval_status: 'approved' })}>
                                                            Approve
                                                        </button>
                                                        <button type="button" className="text-xs text-rose-700 hover:underline" onClick={() => router.patch(route('finance.expenses.approval.update', row.id), { approval_status: 'rejected' })}>
                                                            Reject
                                                        </button>
                                                    </>
                                                )}
                                                {row.payment_status === 'unpaid' && (
                                                    <button type="button" className="text-xs text-indigo-600 hover:underline" onClick={() => router.patch(route('finance.expenses.mark-paid', row.id))}>
                                                        Mark paid
                                                    </button>
                                                )}
                                                <button type="button" className="text-xs text-slate-700 hover:underline" onClick={() => startEdit(row)}>
                                                    Edit
                                                </button>
                                                <button type="button" className="text-xs text-red-600 hover:underline" onClick={() => setDeleteExpenseId(row.id)}>
                                                    Delete
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </section>

                <ConfirmActionModal
                    show={Boolean(deleteExpenseId)}
                    title="Delete this expense?"
                    message="This removes the expense from your ledger."
                    confirmText="Delete"
                    onClose={() => !deleteExpenseBusy && setDeleteExpenseId(null)}
                    processing={deleteExpenseBusy}
                    onConfirm={() => {
                        if (!deleteExpenseId) return;
                        setDeleteExpenseBusy(true);
                        router.delete(route('finance.expenses.destroy', deleteExpenseId), {
                            onFinish: () => {
                                setDeleteExpenseBusy(false);
                                setDeleteExpenseId(null);
                            },
                        });
                    }}
                />
                <ConfirmActionModal
                    show={closeConfirmOpen}
                    title="Close petty-cash day?"
                    message={`Custodian: ${selectedClosingCustodian}\nExpected: ${money(expectedClosingBalance)}\nCounted: ${money(countedClosingBalance)}\nVariance: ${closingVariance > 0 ? '+' : ''}${money(closingVariance)}`}
                    confirmText="Confirm closing"
                    onClose={() => !pettyCashCloseForm.processing && setCloseConfirmOpen(false)}
                    processing={pettyCashCloseForm.processing}
                    onConfirm={submitPettyCashClose}
                />
            </div>
        </AuthenticatedLayout>
    );
}
