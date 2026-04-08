import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, useForm, usePage } from '@inertiajs/react';

const money = (value) =>
    new Intl.NumberFormat(undefined, { style: 'currency', currency: 'AED', minimumFractionDigits: 2 }).format(Number(value || 0));

export default function FinancePayrollIndex({ periods }) {
    const { flash } = usePage().props;
    const form = useForm({
        period_start: new Date(new Date().getFullYear(), new Date().getMonth(), 1).toISOString().slice(0, 10),
        period_end: new Date(new Date().getFullYear(), new Date().getMonth() + 1, 0).toISOString().slice(0, 10),
        notes: '',
    });

    return (
        <AuthenticatedLayout header="Payroll">
            <Head title="Payroll" />

            <div className="space-y-6">
                {flash?.status && <div className="ta-card border-emerald-200 bg-emerald-50 p-3 text-sm text-emerald-700">{flash.status}</div>}

                <Link href={route('finance.index')} className="text-sm text-indigo-600 hover:underline">
                    ← Finance overview
                </Link>

                <section className="ta-card p-5">
                    <h3 className="mb-4 text-sm font-semibold text-slate-700">New payroll period</h3>
                    <p className="mb-4 text-xs text-slate-500">Generate lines from approved clock-in/out attendance, then adjust and mark paid.</p>
                    <form
                        onSubmit={(e) => {
                            e.preventDefault();
                            form.post(route('finance.payroll.store'));
                        }}
                        className="grid gap-3 md:grid-cols-4"
                    >
                        <div>
                            <label className="ta-field-label">Start</label>
                            <input type="date" className="ta-input" value={form.data.period_start} onChange={(e) => form.setData('period_start', e.target.value)} required />
                        </div>
                        <div>
                            <label className="ta-field-label">End</label>
                            <input type="date" className="ta-input" value={form.data.period_end} onChange={(e) => form.setData('period_end', e.target.value)} required />
                        </div>
                        <div className="md:col-span-2">
                            <label className="ta-field-label">Notes</label>
                            <input className="ta-input" value={form.data.notes} onChange={(e) => form.setData('notes', e.target.value)} />
                        </div>
                        <button type="submit" className="ta-btn-primary" disabled={form.processing}>
                            Create period
                        </button>
                    </form>
                </section>

                <section className="ta-card overflow-hidden">
                    <div className="border-b border-slate-200 px-5 py-4">
                        <h3 className="text-sm font-semibold text-slate-700">Periods</h3>
                    </div>
                    <div className="overflow-x-auto">
                        <table className="min-w-full text-sm">
                            <thead className="bg-slate-50 text-left text-xs uppercase text-slate-500">
                                <tr>
                                    <th className="px-5 py-3">Range</th>
                                    <th className="px-5 py-3">Status</th>
                                    <th className="px-5 py-3 text-right">Gross</th>
                                    <th className="px-5 py-3" />
                                </tr>
                            </thead>
                            <tbody>
                                {periods.data.map((p) => (
                                    <tr key={p.id} className="border-t border-slate-100">
                                        <td className="px-5 py-3">
                                            {p.period_start} → {p.period_end}
                                        </td>
                                        <td className="px-5 py-3 capitalize">{p.status}</td>
                                        <td className="px-5 py-3 text-right font-medium">{money(p.gross_total)}</td>
                                        <td className="px-5 py-3">
                                            <Link href={route('finance.payroll.show', p.id)} className="text-indigo-600 hover:underline">
                                                Open
                                            </Link>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </section>
            </div>
        </AuthenticatedLayout>
    );
}
