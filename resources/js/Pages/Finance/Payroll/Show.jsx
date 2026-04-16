import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';

const money = (value) =>
    new Intl.NumberFormat(undefined, { style: 'currency', currency: 'AED', minimumFractionDigits: 2 }).format(Number(value || 0));

export default function FinancePayrollShow({ period }) {
    const { flash } = usePage().props;
    const isDraft = period.status === 'draft';
    const [editingId, setEditingId] = useState(null);

    const lineForm = useForm({
        hours_worked: '',
        hourly_rate: '',
        gross_amount: '',
        notes: '',
    });

    const startEdit = (line) => {
        setEditingId(line.id);
        lineForm.setData({
            hours_worked: String(line.hours_worked),
            hourly_rate: String(line.hourly_rate),
            gross_amount: String(line.gross_amount),
            notes: line.notes || '',
        });
        lineForm.clearErrors();
    };

    const saveLine = (lineId) => {
        lineForm.transform((d) => ({
            hours_worked: parseFloat(d.hours_worked),
            hourly_rate: parseFloat(d.hourly_rate),
            gross_amount: parseFloat(d.gross_amount),
            notes: d.notes || null,
        }));
        lineForm.put(route('finance.payroll.lines.update', { payroll_period: period.id, line: lineId }), {
            onSuccess: () => setEditingId(null),
        });
    };

    return (
        <AuthenticatedLayout header={`Payroll ${period.period_start} – ${period.period_end}`}>
            <Head title="Payroll period" />

            <div className="space-y-6">
                {flash?.status && <div className="ta-card border-emerald-200 bg-emerald-50 p-3 text-sm text-emerald-700">{flash.status}</div>}

                <Link href={route('finance.payroll.index')} className="text-sm text-indigo-600 hover:underline">
                    ← Payroll periods
                </Link>

                <section className="ta-card p-5">
                    <div className="flex flex-wrap items-center justify-between gap-4">
                        <div>
                            <p className="text-xs uppercase text-slate-500">Status</p>
                            <p className="text-lg font-semibold capitalize">{period.status}</p>
                            {period.notes && <p className="text-sm text-slate-600">{period.notes}</p>}
                        </div>
                        <div className="text-right">
                            <p className="text-xs text-slate-500">Total gross</p>
                            <p className="text-2xl font-bold text-slate-900">{money(period.gross_total)}</p>
                        </div>
                    </div>
                    <div className="mt-4 flex flex-wrap gap-2">
                        {isDraft && (
                            <>
                                <button
                                    type="button"
                                    className="rounded-xl border border-indigo-200 bg-indigo-50 px-4 py-2 text-sm font-medium text-indigo-800"
                                    onClick={() => router.post(route('finance.payroll.generate', period.id))}
                                >
                                    Sync from attendance
                                </button>
                                <button
                                    type="button"
                                    className="rounded-xl border border-slate-200 bg-white px-4 py-2 text-sm text-slate-700"
                                    onClick={() => router.patch(route('finance.payroll.lock', period.id))}
                                >
                                    Lock period
                                </button>
                            </>
                        )}
                        {(period.status === 'draft' || period.status === 'locked') && (
                            <button
                                type="button"
                                className="ta-btn-primary"
                                onClick={() => router.patch(route('finance.payroll.mark-paid', period.id))}
                            >
                                Mark payroll paid
                            </button>
                        )}
                    </div>
                </section>

                <section className="ta-card overflow-hidden">
                    <div className="border-b border-slate-200 px-5 py-4">
                        <h3 className="text-sm font-semibold text-slate-700">Staff lines</h3>
                        <p className="text-xs text-slate-500">Hours come from clock-in/out logs. Set hourly rate on each staff profile.</p>
                    </div>
                    <div className="overflow-x-auto">
                        <table className="min-w-full text-sm">
                            <thead className="bg-slate-50 text-left text-xs uppercase text-slate-500">
                                <tr>
                                    <th className="px-5 py-3">Staff</th>
                                    <th className="px-5 py-3 text-right">Hours</th>
                                    <th className="px-5 py-3 text-right">Rate</th>
                                    <th className="px-5 py-3 text-right">Gross</th>
                                    <th className="px-5 py-3">Notes</th>
                                    {isDraft && <th className="px-5 py-3" />}
                                </tr>
                            </thead>
                            <tbody>
                                {period.lines.map((line) => (
                                    <tr key={line.id} className="border-t border-slate-100">
                                        <td className="px-5 py-3">
                                            <div className="font-medium text-slate-800">{line.staff_name}</div>
                                            <div className="text-xs text-slate-500">{line.employee_code}</div>
                                        </td>
                                        {editingId === line.id && isDraft ? (
                                            <>
                                                <td className="px-5 py-3">
                                                    <input
                                                        type="number"
                                                        step="0.01"
                                                        min="0"
                                                        className="ta-input"
                                                        value={lineForm.data.hours_worked}
                                                        onChange={(e) => lineForm.setData('hours_worked', e.target.value)}
                                                    />
                                                </td>
                                                <td className="px-5 py-3">
                                                    <input
                                                        type="number"
                                                        step="0.01"
                                                        min="0"
                                                        className="ta-input"
                                                        value={lineForm.data.hourly_rate}
                                                        onChange={(e) => lineForm.setData('hourly_rate', e.target.value)}
                                                    />
                                                </td>
                                                <td className="px-5 py-3">
                                                    <input
                                                        type="number"
                                                        step="0.01"
                                                        min="0"
                                                        className="ta-input"
                                                        value={lineForm.data.gross_amount}
                                                        onChange={(e) => lineForm.setData('gross_amount', e.target.value)}
                                                    />
                                                </td>
                                                <td className="px-5 py-3">
                                                    <input className="ta-input" value={lineForm.data.notes} onChange={(e) => lineForm.setData('notes', e.target.value)} />
                                                </td>
                                                <td className="px-5 py-3">
                                                    <div className="flex gap-2">
                                                        <button type="button" className="text-xs text-indigo-600" onClick={() => saveLine(line.id)} disabled={lineForm.processing}>
                                                            Save
                                                        </button>
                                                        <button type="button" className="text-xs text-slate-500" onClick={() => setEditingId(null)}>
                                                            Cancel
                                                        </button>
                                                    </div>
                                                    {lineForm.errors.hours_worked && <p className="text-xs text-red-600">{lineForm.errors.hours_worked}</p>}
                                                </td>
                                            </>
                                        ) : (
                                            <>
                                                <td className="px-5 py-3 text-right">{line.hours_worked}</td>
                                                <td className="px-5 py-3 text-right">{money(line.hourly_rate)}</td>
                                                <td className="px-5 py-3 text-right font-medium">{money(line.gross_amount)}</td>
                                                <td className="px-5 py-3 text-slate-600">{line.notes || '—'}</td>
                                                {isDraft && (
                                                    <td className="px-5 py-3">
                                                        <button type="button" className="text-xs text-indigo-600 hover:underline" onClick={() => startEdit(line)}>
                                                            Edit
                                                        </button>
                                                    </td>
                                                )}
                                            </>
                                        )}
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
