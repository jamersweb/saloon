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
        pay_basis: 'hourly',
        hours_worked: '',
        hourly_rate: '',
        basic_salary: '',
        bonus_amount: '',
        deduction_amount: '',
        payment_method: 'bank_transfer',
        notes: '',
    });

    const startEdit = (line) => {
        setEditingId(line.id);
        lineForm.setData({
            pay_basis: line.pay_basis,
            hours_worked: String(line.hours_worked),
            hourly_rate: String(line.hourly_rate),
            basic_salary: String(line.basic_salary),
            bonus_amount: String(line.bonus_amount),
            deduction_amount: String(line.deduction_amount),
            payment_method: line.payment_method || 'bank_transfer',
            notes: line.notes || '',
        });
        lineForm.clearErrors();
    };

    const saveLine = (lineId) => {
        lineForm.transform((d) => ({
            pay_basis: d.pay_basis,
            hours_worked: parseFloat(d.hours_worked || 0),
            hourly_rate: parseFloat(d.hourly_rate || 0),
            basic_salary: parseFloat(d.basic_salary || 0),
            bonus_amount: parseFloat(d.bonus_amount || 0),
            deduction_amount: parseFloat(d.deduction_amount || 0),
            payment_method: d.payment_method,
            notes: d.notes || null,
        }));
        lineForm.put(route('finance.payroll.lines.update', { payroll_period: period.id, line: lineId }), {
            onSuccess: () => setEditingId(null),
        });
    };

    return (
        <AuthenticatedLayout header={`Payroll ${period.period_start} - ${period.period_end}`}>
            <Head title="Payroll period" />

            <div className="space-y-6">
                {flash?.status && <div className="ta-card border-emerald-200 bg-emerald-50 p-3 text-sm text-emerald-700">{flash.status}</div>}

                <Link href={route('finance.payroll.index')} className="text-sm text-indigo-600 hover:underline">
                    {'<-'} Payroll periods
                </Link>

                <section className="ta-card p-5">
                    <div className="grid gap-4 md:grid-cols-3">
                        <div>
                            <p className="text-xs uppercase text-slate-500">Status</p>
                            <p className="text-lg font-semibold capitalize">{period.status}</p>
                            {period.notes && <p className="text-sm text-slate-600">{period.notes}</p>}
                        </div>
                        <div>
                            <p className="text-xs uppercase text-slate-500">Gross total</p>
                            <p className="text-2xl font-bold text-slate-900">{money(period.gross_total)}</p>
                        </div>
                        <div>
                            <p className="text-xs uppercase text-slate-500">Net total</p>
                            <p className="text-2xl font-bold text-emerald-700">{money(period.net_total)}</p>
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
                        <p className="text-xs text-slate-500">Fixed-salary staff use monthly salary. Hourly staff use attendance hours multiplied by hourly rate.</p>
                    </div>
                    <div className="overflow-x-auto">
                        <table className="min-w-full text-sm">
                            <thead className="bg-slate-50 text-left text-xs uppercase text-slate-500">
                                <tr>
                                    <th className="px-5 py-3">Staff</th>
                                    <th className="px-5 py-3">Basis</th>
                                    <th className="px-5 py-3 text-right">Hours</th>
                                    <th className="px-5 py-3 text-right">Rate</th>
                                    <th className="px-5 py-3 text-right">Basic</th>
                                    <th className="px-5 py-3 text-right">Bonus</th>
                                    <th className="px-5 py-3 text-right">Deduction</th>
                                    <th className="px-5 py-3 text-right">Net</th>
                                    <th className="px-5 py-3">Method</th>
                                    <th className="px-5 py-3">Notes</th>
                                    <th className="px-5 py-3" />
                                </tr>
                            </thead>
                            <tbody>
                                {period.lines.map((line) => (
                                    <tr key={line.id} className="border-t border-slate-100 align-top">
                                        <td className="px-5 py-3">
                                            <div className="font-medium text-slate-800">{line.staff_name}</div>
                                            <div className="text-xs text-slate-500">{line.employee_code}</div>
                                            {line.finance_expense_entry_id && <div className="mt-1 text-xs text-emerald-700">Posted to finance</div>}
                                        </td>
                                        {editingId === line.id && isDraft ? (
                                            <>
                                                <td className="px-5 py-3">
                                                    <select className="ta-input" value={lineForm.data.pay_basis} onChange={(e) => lineForm.setData('pay_basis', e.target.value)}>
                                                        <option value="hourly">Hourly</option>
                                                        <option value="fixed_salary">Fixed salary</option>
                                                    </select>
                                                </td>
                                                <td className="px-5 py-3">
                                                    <input type="number" step="0.01" min="0" className="ta-input" value={lineForm.data.hours_worked} onChange={(e) => lineForm.setData('hours_worked', e.target.value)} />
                                                </td>
                                                <td className="px-5 py-3">
                                                    <input type="number" step="0.01" min="0" className="ta-input" value={lineForm.data.hourly_rate} onChange={(e) => lineForm.setData('hourly_rate', e.target.value)} />
                                                </td>
                                                <td className="px-5 py-3">
                                                    <input type="number" step="0.01" min="0" className="ta-input" value={lineForm.data.basic_salary} onChange={(e) => lineForm.setData('basic_salary', e.target.value)} />
                                                </td>
                                                <td className="px-5 py-3">
                                                    <input type="number" step="0.01" min="0" className="ta-input" value={lineForm.data.bonus_amount} onChange={(e) => lineForm.setData('bonus_amount', e.target.value)} />
                                                </td>
                                                <td className="px-5 py-3">
                                                    <input type="number" step="0.01" min="0" className="ta-input" value={lineForm.data.deduction_amount} onChange={(e) => lineForm.setData('deduction_amount', e.target.value)} />
                                                </td>
                                                <td className="px-5 py-3 text-right font-medium">
                                                    {money(Math.max(0, (parseFloat(lineForm.data.basic_salary || 0) + parseFloat(lineForm.data.bonus_amount || 0)) - parseFloat(lineForm.data.deduction_amount || 0)))}
                                                </td>
                                                <td className="px-5 py-3">
                                                    <select className="ta-input" value={lineForm.data.payment_method} onChange={(e) => lineForm.setData('payment_method', e.target.value)}>
                                                        <option value="bank_transfer">Bank transfer</option>
                                                        <option value="cash">Cash</option>
                                                        <option value="card">Card</option>
                                                        <option value="wallet">Wallet</option>
                                                        <option value="other">Other</option>
                                                    </select>
                                                </td>
                                                <td className="px-5 py-3">
                                                    <input className="ta-input" value={lineForm.data.notes} onChange={(e) => lineForm.setData('notes', e.target.value)} />
                                                </td>
                                                <td className="px-5 py-3">
                                                    <div className="flex flex-col gap-2">
                                                        <button type="button" className="text-xs text-indigo-600" onClick={() => saveLine(line.id)} disabled={lineForm.processing}>
                                                            Save
                                                        </button>
                                                        <button type="button" className="text-xs text-slate-500" onClick={() => setEditingId(null)}>
                                                            Cancel
                                                        </button>
                                                    </div>
                                                </td>
                                            </>
                                        ) : (
                                            <>
                                                <td className="px-5 py-3 capitalize">{line.pay_basis === 'fixed_salary' ? 'Fixed salary' : 'Hourly'}</td>
                                                <td className="px-5 py-3 text-right">{line.hours_worked}</td>
                                                <td className="px-5 py-3 text-right">{money(line.hourly_rate)}</td>
                                                <td className="px-5 py-3 text-right">{money(line.basic_salary)}</td>
                                                <td className="px-5 py-3 text-right text-emerald-700">{money(line.bonus_amount)}</td>
                                                <td className="px-5 py-3 text-right text-rose-700">{money(line.deduction_amount)}</td>
                                                <td className="px-5 py-3 text-right font-medium">{money(line.net_amount)}</td>
                                                <td className="px-5 py-3 text-slate-600">{line.payment_method?.replace('_', ' ')}</td>
                                                <td className="px-5 py-3 text-slate-600">{line.notes || '-'}</td>
                                                <td className="px-5 py-3">
                                                    <div className="flex flex-col gap-2">
                                                        <a href={route('finance.payroll.lines.payslip', { payroll_period: period.id, line: line.id })} className="text-xs text-slate-700 hover:underline" target="_blank" rel="noreferrer">
                                                            Payslip PDF
                                                        </a>
                                                        {isDraft && (
                                                            <button type="button" className="text-left text-xs text-indigo-600 hover:underline" onClick={() => startEdit(line)}>
                                                                Edit
                                                            </button>
                                                        )}
                                                    </div>
                                                </td>
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
