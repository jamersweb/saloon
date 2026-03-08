import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, useForm, usePage } from '@inertiajs/react';

export default function AttendanceIndex({ logs, staffProfiles }) {
    const { flash, errors, auth } = usePage().props;
    const isStaff = auth?.user?.role?.name === 'staff';
    const myProfileName = staffProfiles?.[0]?.name || 'My profile';
    const clockInForm = useForm({ staff_profile_id: '' });
    const clockOutForm = useForm({ staff_profile_id: '' });

    return (
        <AuthenticatedLayout header={<h2 className="text-xl font-semibold leading-tight text-gray-800">Attendance</h2>}>
            <Head title="Attendance" />
            <div className="py-8"><div className="mx-auto max-w-7xl space-y-6 sm:px-6 lg:px-8">
                {flash?.status && <div className="rounded bg-green-100 p-3 text-green-700">{flash.status}</div>}
                {Object.keys(errors).length > 0 && <div className="rounded bg-red-100 p-3 text-red-700">{Object.values(errors)[0]}</div>}
                <div className="grid gap-3 rounded bg-white p-4 shadow md:grid-cols-3">
                    <form onSubmit={(e) => { e.preventDefault(); clockInForm.post(route('attendance.clock-in')); }} className="space-y-2">
                        {isStaff ? (
                            <div className="w-full rounded border bg-slate-50 p-2 text-sm text-slate-600">{myProfileName}</div>
                        ) : (
                            <select className="w-full rounded border p-2" value={clockInForm.data.staff_profile_id} onChange={(e) => clockInForm.setData('staff_profile_id', e.target.value)}><option value="">My profile</option>{staffProfiles.map((s) => <option key={s.id} value={s.id}>{s.name}</option>)}</select>
                        )}
                        <button className="w-full rounded bg-indigo-600 py-2 text-white">Clock In</button>
                    </form>
                    <form onSubmit={(e) => { e.preventDefault(); clockOutForm.post(route('attendance.clock-out')); }} className="space-y-2">
                        {isStaff ? (
                            <div className="w-full rounded border bg-slate-50 p-2 text-sm text-slate-600">{myProfileName}</div>
                        ) : (
                            <select className="w-full rounded border p-2" value={clockOutForm.data.staff_profile_id} onChange={(e) => clockOutForm.setData('staff_profile_id', e.target.value)}><option value="">My profile</option>{staffProfiles.map((s) => <option key={s.id} value={s.id}>{s.name}</option>)}</select>
                        )}
                        <button className="w-full rounded bg-gray-700 py-2 text-white">Clock Out</button>
                    </form>
                </div>
                <div className="overflow-auto rounded bg-white p-4 shadow"><table className="min-w-full text-sm"><thead><tr className="border-b text-left"><th className="py-2">Date</th><th>Staff</th><th>In</th><th>Out</th><th>Late</th></tr></thead><tbody>{logs.map((l) => <tr key={l.id} className="border-b"><td className="py-2">{l.attendance_date?.slice(0, 10)}</td><td>{l.staff_name}</td><td>{l.clock_in}</td><td>{l.clock_out}</td><td>{l.late_minutes}</td></tr>)}</tbody></table></div>
            </div></div>
        </AuthenticatedLayout>
    );
}
