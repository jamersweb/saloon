import TablePagination from '@/Components/TablePagination';
import { useEffect, useMemo, useState } from 'react';

const ROWS_PER_PAGE = 10;

const matchesSearch = (values, term) => values
    .filter(Boolean)
    .join(' ')
    .toLowerCase()
    .includes(term);

export default function PointsSection({
    fieldError,
    canManage,
    bonusForm,
    pointsForm,
    customers,
    recentLedgers,
}) {
    const [ledgerSearch, setLedgerSearch] = useState('');
    const [ledgerTypeFilter, setLedgerTypeFilter] = useState('');
    const [ledgerReasonFilter, setLedgerReasonFilter] = useState('');
    const [ledgerPage, setLedgerPage] = useState(1);

    const ledgerReasonOptions = useMemo(() => (
        [...new Set((recentLedgers || []).map((row) => row.reason).filter(Boolean))]
            .sort((a, b) => String(a).localeCompare(String(b)))
    ), [recentLedgers]);

    const filteredLedgers = useMemo(() => {
        const term = ledgerSearch.trim().toLowerCase();

        return (recentLedgers || []).filter((row) => {
            const points = Number(row.points_change || 0);

            if (ledgerTypeFilter === 'earned' && points <= 0) {
                return false;
            }

            if (ledgerTypeFilter === 'deducted' && points >= 0) {
                return false;
            }

            if (ledgerReasonFilter && row.reason !== ledgerReasonFilter) {
                return false;
            }

            if (!term) {
                return true;
            }

            return matchesSearch([
                row.customer_name,
                row.points_change,
                row.balance_after,
                row.reason,
                row.created_by,
                row.created_at ? new Date(row.created_at).toLocaleString() : '',
            ], term);
        });
    }, [ledgerReasonFilter, ledgerSearch, ledgerTypeFilter, recentLedgers]);

    const ledgerTotalPages = Math.max(1, Math.ceil(filteredLedgers.length / ROWS_PER_PAGE));
    const ledgerPageRows = useMemo(
        () => filteredLedgers.slice((ledgerPage - 1) * ROWS_PER_PAGE, ledgerPage * ROWS_PER_PAGE),
        [filteredLedgers, ledgerPage],
    );

    useEffect(() => {
        setLedgerPage(1);
    }, [ledgerReasonFilter, ledgerSearch, ledgerTypeFilter]);

    useEffect(() => {
        if (ledgerPage > ledgerTotalPages) {
            setLedgerPage(ledgerTotalPages);
        }
    }, [ledgerPage, ledgerTotalPages]);

    return (
        <div className="space-y-6">
            <section className="ta-card p-5">
                <h3 className="mb-4 text-sm font-semibold text-slate-700">Award configured bonus</h3>
                <p className="mb-3 text-xs text-slate-600">Uses bonus point amounts from Program and tiers - Auto earn rules (referral, review, birthday).</p>
                <form onSubmit={(e) => { e.preventDefault(); bonusForm.post(route('loyalty.bonus.award')); }} className="grid gap-3 md:grid-cols-3">
                    <div><label className="ta-field-label">Customer</label><select className="ta-input" value={bonusForm.data.customer_id} onChange={(e) => bonusForm.setData('customer_id', e.target.value)} required><option value="">Select customer</option>{customers.map((customer) => <option key={customer.id} value={customer.id}>{customer.name} ({customer.points} pts)</option>)}</select>{fieldError(bonusForm, 'customer_id')}</div>
                    <div><label className="ta-field-label">Bonus type</label><select className="ta-input" value={bonusForm.data.bonus_type} onChange={(e) => bonusForm.setData('bonus_type', e.target.value)}><option value="referral">Referral</option><option value="review">Review</option><option value="birthday">Birthday</option></select>{fieldError(bonusForm, 'bonus_type')}</div>
                    <button className="ta-btn-primary" disabled={bonusForm.processing || !canManage}>Award bonus</button>
                </form>
            </section>

            <section className="ta-card p-5">
                <h3 className="mb-4 text-sm font-semibold text-slate-700">Add / deduct points</h3>
                <form onSubmit={(e) => { e.preventDefault(); pointsForm.post(route('loyalty.ledger.store'), { onSuccess: () => pointsForm.reset('points_change', 'reason', 'reference', 'notes') }); }} className="grid gap-3 md:grid-cols-5">
                    <div><label className="ta-field-label">Customer</label><select className="ta-input" value={pointsForm.data.customer_id} onChange={(e) => pointsForm.setData('customer_id', e.target.value)} required><option value="">Select customer</option>{customers.map((customer) => <option key={customer.id} value={customer.id}>{customer.name} ({customer.points} pts)</option>)}</select>{fieldError(pointsForm, 'customer_id')}</div>
                    <div><label className="ta-field-label">Points</label><input className="ta-input" type="number" placeholder="Points (+/-)" value={pointsForm.data.points_change} onChange={(e) => pointsForm.setData('points_change', e.target.value)} required />{fieldError(pointsForm, 'points_change')}</div>
                    <div><label className="ta-field-label">Reason</label><input className="ta-input" placeholder="Reason" value={pointsForm.data.reason} onChange={(e) => pointsForm.setData('reason', e.target.value)} required />{fieldError(pointsForm, 'reason')}</div>
                    <div><label className="ta-field-label">Reference</label><input className="ta-input" placeholder="Reference" value={pointsForm.data.reference} onChange={(e) => pointsForm.setData('reference', e.target.value)} />{fieldError(pointsForm, 'reference')}</div>
                    <button className="ta-btn-primary" disabled={pointsForm.processing || !canManage}>Apply</button>
                </form>
            </section>

            <section className="ta-card overflow-hidden">
                <div className="border-b border-slate-200 px-5 py-4">
                    <div className="flex flex-col gap-3 xl:flex-row xl:items-end xl:justify-between">
                        <h3 className="text-sm font-semibold text-slate-700">Recent loyalty ledger</h3>
                        <div className="grid w-full gap-2 md:grid-cols-3 xl:max-w-3xl">
                            <div>
                                <label className="ta-field-label">Search</label>
                                <input className="ta-input" value={ledgerSearch} onChange={(e) => setLedgerSearch(e.target.value)} placeholder="Customer, reason, staff" />
                            </div>
                            <div>
                                <label className="ta-field-label">Type</label>
                                <select className="ta-input" value={ledgerTypeFilter} onChange={(e) => setLedgerTypeFilter(e.target.value)}>
                                    <option value="">All changes</option>
                                    <option value="earned">Earned</option>
                                    <option value="deducted">Deducted</option>
                                </select>
                            </div>
                            <div>
                                <label className="ta-field-label">Reason</label>
                                <select className="ta-input" value={ledgerReasonFilter} onChange={(e) => setLedgerReasonFilter(e.target.value)}>
                                    <option value="">All reasons</option>
                                    {ledgerReasonOptions.map((reason) => <option key={reason} value={reason}>{reason}</option>)}
                                </select>
                            </div>
                        </div>
                    </div>
                </div>
                <div className="overflow-x-auto">
                    <table className="min-w-full text-sm">
                        <thead className="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
                            <tr><th className="px-5 py-3">Date</th><th className="px-5 py-3">Customer</th><th className="px-5 py-3">Change</th><th className="px-5 py-3">Balance</th><th className="px-5 py-3">Reason</th><th className="px-5 py-3">By</th></tr>
                        </thead>
                        <tbody>
                            {ledgerPageRows.map((row) => (
                                <tr key={row.id} className="border-t border-slate-100">
                                    <td className="px-5 py-3 text-slate-600">{new Date(row.created_at).toLocaleString()}</td>
                                    <td className="px-5 py-3 text-slate-700">{row.customer_name}</td>
                                    <td className={`px-5 py-3 font-semibold ${row.points_change >= 0 ? 'text-emerald-600' : 'text-red-600'}`}>{row.points_change >= 0 ? `+${row.points_change}` : row.points_change}</td>
                                    <td className="px-5 py-3 text-slate-700">{row.balance_after}</td>
                                    <td className="px-5 py-3 text-slate-600">{row.reason}</td>
                                    <td className="px-5 py-3 text-slate-600">{row.created_by || '-'}</td>
                                </tr>
                            ))}
                            {ledgerPageRows.length === 0 ? (
                                <tr className="border-t border-slate-100"><td className="px-5 py-6 text-sm text-slate-500" colSpan="6">No ledger rows match the current filters.</td></tr>
                            ) : null}
                        </tbody>
                    </table>
                </div>
                <TablePagination page={ledgerPage} totalPages={ledgerTotalPages} totalItems={filteredLedgers.length} pageSize={ROWS_PER_PAGE} onPageChange={setLedgerPage} itemLabel="ledger rows" />
            </section>
        </div>
    );
}
