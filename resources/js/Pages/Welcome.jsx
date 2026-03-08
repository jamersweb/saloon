import { Head, Link } from '@inertiajs/react';

export default function Welcome({ auth }) {
    return (
        <>
            <Head title="Welcome | Vina Management System" />
            <div className="relative min-h-screen overflow-hidden bg-slate-50">
                <div className="pointer-events-none absolute -top-20 -right-24 h-80 w-80 rounded-full bg-indigo-100 blur-3xl" />
                <div className="pointer-events-none absolute -bottom-24 -left-20 h-80 w-80 rounded-full bg-slate-200 blur-3xl" />

                <div className="relative mx-auto flex min-h-screen w-full max-w-6xl items-center px-6 py-12">
                    <div className="ta-card w-full p-6 md:p-10">
                        <div className="grid items-center gap-10 md:grid-cols-2">
                            <section className="space-y-5">
                                <img
                                    src="/images/vina-logo.png"
                                    alt="Vina logo"
                                    className="h-auto w-full max-w-md"
                                />
                                <h1 className="text-3xl font-semibold leading-tight text-slate-800 md:text-4xl">
                                    Vina Management System
                                </h1>
                                <p className="max-w-xl text-base text-slate-600">
                                    A WORLD OF ENDLESS POSSIBILITIES IN LUXURY CARE & BEAUTY SERVICES
                                </p>
                            </section>

                            <section className="space-y-4">
                                {auth.user ? (
                                    <Link
                                        href={route('dashboard')}
                                        className="ta-btn-primary inline-flex w-full items-center justify-center text-center"
                                    >
                                        Go to Dashboard
                                    </Link>
                                ) : (
                                    <div className="space-y-3">
                                        <Link
                                            href={route('login')}
                                            className="ta-btn-primary inline-flex w-full items-center justify-center text-center"
                                        >
                                            Log in
                                        </Link>
                                        <Link
                                            href={route('register')}
                                            className="ta-btn-secondary inline-flex w-full items-center justify-center text-center"
                                        >
                                            Register
                                        </Link>
                                    </div>
                                )}
                                <Link
                                    href={route('public.booking')}
                                    className="inline-flex w-full items-center justify-center rounded-xl border border-slate-300 bg-white px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-100"
                                >
                                    Book Appointment
                                </Link>
                            </section>
                        </div>
                    </div>
                </div>
            </div>
        </>
    );
}
