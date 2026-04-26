import ApplicationLogo from '@/Components/ApplicationLogo';
import { Head } from '@inertiajs/react';

const formatDate = (value) => value ? new Date(value).toLocaleDateString() : 'N/A';
const formatDateTime = (value) => value ? new Date(value).toLocaleString() : 'N/A';
const formatCurrency = (value, currencyCode = 'AED') => value === null || value === undefined ? 'N/A' : new Intl.NumberFormat(undefined, { style: 'currency', currency: currencyCode }).format(Number(value));

export default function CustomerPortal({ customer }) {
    return (
        <>
            <Head title={`Customer Portal | ${customer.name}`} />

            <div className="min-h-screen bg-slate-100 px-4 py-8">
                <div className="mx-auto max-w-5xl space-y-6">
                    <div className="ta-card p-6">
                        <ApplicationLogo className="h-auto w-64" />
                        <div className="mt-6 flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
                            <div>
                                <h1 className="text-3xl font-semibold text-slate-800">{customer.name}</h1>
                                <p className="mt-2 text-sm text-slate-500">{customer.phone || 'No phone on file'} {customer.email ? `| ${customer.email}` : ''}</p>
                            </div>
                            <div className="rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-600">
                                Your loyalty, package, gift card, and visit summary is available here.
                            </div>
                        </div>
                    </div>

                    <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
                        <div className="ta-card p-5">
                            <div className="text-xs font-semibold uppercase tracking-wide text-slate-500">Points</div>
                            <div className="mt-2 text-3xl font-semibold text-slate-800">{customer.points}</div>
                            <p className="text-sm text-slate-500">{customer.tier || 'No tier yet'}</p>
                        </div>
                        <div className="ta-card p-5">
                            <div className="text-xs font-semibold uppercase tracking-wide text-slate-500">Current Card</div>
                            <div className="mt-2 text-xl font-semibold text-slate-800">{customer.current_card || 'No card assigned'}</div>
                            <p className="text-sm text-slate-500">{customer.card_status || 'Unavailable'}</p>
                            <p className="mt-1 text-xs text-slate-400">Expires: {formatDate(customer.card_expires_at)}</p>
                        </div>
                        <div className="ta-card p-5">
                            <div className="text-xs font-semibold uppercase tracking-wide text-slate-500">Packages</div>
                            <div className="mt-2 text-3xl font-semibold text-slate-800">{customer.packages.length}</div>
                            <p className="text-sm text-slate-500">Prepaid sessions and stored balances.</p>
                        </div>
                        <div className="ta-card p-5">
                            <div className="text-xs font-semibold uppercase tracking-wide text-slate-500">Gift Cards</div>
                            <div className="mt-2 text-3xl font-semibold text-slate-800">{customer.gift_cards.length}</div>
                            <p className="text-sm text-slate-500">Track remaining value anytime.</p>
                        </div>
                    </div>

                    <div className="grid gap-4 md:grid-cols-2">
                        <div className="ta-card p-5">
                            <div className="text-xs font-semibold uppercase tracking-wide text-slate-500">Points Spent</div>
                            <div className="mt-2 text-3xl font-semibold text-rose-700">{customer.points_spent ?? 0}</div>
                            <p className="text-sm text-slate-500">Total points redeemed so far.</p>
                        </div>
                        <div className="ta-card p-5">
                            <div className="text-xs font-semibold uppercase tracking-wide text-slate-500">Points Remaining</div>
                            <div className="mt-2 text-3xl font-semibold text-emerald-700">{customer.points_remaining ?? customer.points ?? 0}</div>
                            <p className="text-sm text-slate-500">Available points balance right now.</p>
                        </div>
                    </div>

                    <div className="grid gap-6 lg:grid-cols-2">
                        <section className="ta-card overflow-hidden">
                            <div className="border-b border-slate-200 bg-slate-50 px-5 py-4 text-sm font-semibold text-slate-700">Package Summary</div>
                            <div className="divide-y divide-slate-100">
                                {customer.packages.length === 0 && <div className="px-5 py-4 text-sm text-slate-500">No package balances available.</div>}
                                {customer.packages.map((pkg, index) => (
                                    <div key={`${pkg.name}-${index}`} className="px-5 py-4 text-sm">
                                        <div className="font-medium text-slate-700">{pkg.name || 'Unnamed package'}</div>
                                        <div className="mt-1 text-slate-500">Remaining sessions: {pkg.remaining_sessions ?? 'N/A'}</div>
                                        <div className="mt-1 text-slate-500">Remaining value: {formatCurrency(pkg.remaining_value)}</div>
                                        <div className="mt-1 text-xs text-slate-400">Status: {pkg.status} | Expires: {formatDate(pkg.expires_at)}</div>
                                    </div>
                                ))}
                            </div>
                        </section>

                        <section className="ta-card overflow-hidden">
                            <div className="border-b border-slate-200 bg-slate-50 px-5 py-4 text-sm font-semibold text-slate-700">Gift Cards</div>
                            <div className="divide-y divide-slate-100">
                                {customer.gift_cards.length === 0 && <div className="px-5 py-4 text-sm text-slate-500">No gift cards available.</div>}
                                {customer.gift_cards.map((giftCard) => (
                                    <div key={giftCard.code} className="px-5 py-4 text-sm">
                                        <div className="font-medium text-slate-700">{giftCard.code}</div>
                                        <div className="mt-1 text-slate-500">Remaining balance: {formatCurrency(giftCard.remaining_value)}</div>
                                        <div className="mt-1 text-xs text-slate-400">Status: {giftCard.status} | Expires: {formatDate(giftCard.expires_at)}</div>
                                    </div>
                                ))}
                            </div>
                        </section>
                    </div>

                    <section className="ta-card overflow-hidden">
                        <div className="border-b border-slate-200 bg-slate-50 px-5 py-4 text-sm font-semibold text-slate-700">Service History</div>
                        <div className="overflow-x-auto">
                            <table className="min-w-full text-sm">
                                <thead className="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
                                    <tr>
                                        <th className="px-5 py-3">Date</th>
                                        <th className="px-5 py-3">Service</th>
                                        <th className="px-5 py-3">Staff</th>
                                        <th className="px-5 py-3">Status</th>
                                        <th className="px-5 py-3">Notes</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {customer.service_history.length === 0 && (
                                        <tr>
                                            <td className="px-5 py-4 text-slate-500" colSpan="5">No visits are available yet.</td>
                                        </tr>
                                    )}
                                    {customer.service_history.map((visit) => (
                                        <tr key={visit.id} className="border-t border-slate-100">
                                            <td className="px-5 py-3">{formatDateTime(visit.scheduled_start)}</td>
                                            <td className="px-5 py-3">{visit.service_name || 'N/A'}</td>
                                            <td className="px-5 py-3">{visit.staff_name || 'N/A'}</td>
                                            <td className="px-5 py-3">{visit.status}</td>
                                            <td className="px-5 py-3">{visit.notes || '-'}</td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    </section>
                </div>
            </div>
        </>
    );
}
