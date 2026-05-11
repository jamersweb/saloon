import Checkbox from '@/Components/Checkbox';
import InputError from '@/Components/InputError';
import PrimaryButton from '@/Components/PrimaryButton';
import TextInput from '@/Components/TextInput';
import GuestLayout from '@/Layouts/GuestLayout';
import { Head, Link, useForm } from '@inertiajs/react';
import { useEffect, useState } from 'react';

export default function Login({ status, canResetPassword, recaptchaSiteKey }) {
    const { data, setData, post, processing, errors, reset } = useForm({
        email: '',
        password: '',
        remember: false,
        'g-recaptcha-response': '',
    });
    const [captchaReady, setCaptchaReady] = useState(false);
    const usesRecaptcha = Boolean(recaptchaSiteKey);

    useEffect(() => {
        if (! usesRecaptcha) {
            return undefined;
        }

        const renderCaptcha = () => {
            const container = document.getElementById('login-recaptcha');
            if (! window.grecaptcha || ! container || container.dataset.widgetId) {
                return;
            }

            const widgetId = window.grecaptcha.render(container, {
                sitekey: recaptchaSiteKey,
                callback: (token) => setData('g-recaptcha-response', token),
                'expired-callback': () => setData('g-recaptcha-response', ''),
                'error-callback': () => setData('g-recaptcha-response', ''),
            });

            container.dataset.widgetId = String(widgetId);
            setCaptchaReady(true);
        };

        const existingScript = document.querySelector('script[data-recaptcha-script="true"]');
        if (existingScript) {
            if (window.grecaptcha) {
                renderCaptcha();
            } else {
                existingScript.addEventListener('load', renderCaptcha, { once: true });
            }

            return undefined;
        }

        const script = document.createElement('script');
        script.src = 'https://www.google.com/recaptcha/api.js?render=explicit';
        script.async = true;
        script.defer = true;
        script.dataset.recaptchaScript = 'true';
        script.addEventListener('load', renderCaptcha, { once: true });
        document.body.appendChild(script);

        return undefined;
    }, [usesRecaptcha, recaptchaSiteKey, setData]);

    const submit = (e) => {
        e.preventDefault();

        post(route('login'), {
            onFinish: () => {
                reset('password');

                if (window.grecaptcha && usesRecaptcha) {
                    const container = document.getElementById('login-recaptcha');
                    const widgetId = container?.dataset?.widgetId;
                    if (widgetId !== undefined) {
                        window.grecaptcha.reset(Number(widgetId));
                    }
                    setData('g-recaptcha-response', '');
                }
            },
        });
    };

    return (
        <GuestLayout>
            <Head title="Sign in" />

            {status && (
                <div className="mb-4 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-medium text-emerald-700">
                    {status}
                </div>
            )}

            <div className="space-y-5">
                <div className="space-y-1 text-center">
                    <h1 className="text-2xl font-semibold tracking-tight text-slate-800">Welcome Back</h1>
                    <p className="text-sm text-slate-600">Sign in to manage appointments, staff, and customer journeys.</p>
                </div>

                <form onSubmit={submit} className="space-y-5">
                    <div className="space-y-2">
                        <label htmlFor="email" className="text-sm font-medium text-slate-700">Email</label>
                        <TextInput
                            id="email"
                            type="email"
                            name="email"
                            value={data.email}
                            className="ta-input mt-0 block w-full border-slate-300 bg-slate-100/70 px-4 py-3 text-base shadow-sm focus:bg-white"
                            autoComplete="username"
                            isFocused={true}
                            onChange={(e) => setData('email', e.target.value)}
                        />
                        <InputError message={errors.email} className="mt-2" />
                    </div>

                    <div className="space-y-2">
                        <label htmlFor="password" className="text-sm font-medium text-slate-700">Password</label>
                        <TextInput
                            id="password"
                            type="password"
                            name="password"
                            value={data.password}
                            className="ta-input mt-0 block w-full border-slate-300 bg-slate-100/70 px-4 py-3 text-base shadow-sm focus:bg-white"
                            autoComplete="current-password"
                            onChange={(e) => setData('password', e.target.value)}
                        />
                        <InputError message={errors.password} className="mt-2" />
                    </div>

                    <div className="flex items-center justify-between gap-4">
                        <label className="flex items-center">
                            <Checkbox
                                name="remember"
                                checked={data.remember}
                                onChange={(e) =>
                                    setData('remember', e.target.checked)
                                }
                            />
                            <span className="ms-2 text-sm text-slate-700">
                                Remember me
                            </span>
                        </label>

                        {canResetPassword && (
                            <Link
                                href={route('password.request')}
                                className="text-sm font-medium text-slate-700 underline decoration-slate-400 underline-offset-4 hover:text-slate-900"
                            >
                                Forgot your password?
                            </Link>
                        )}
                    </div>

                    <div className="pt-1">
                        {usesRecaptcha && (
                            <div className="space-y-2">
                                <div id="login-recaptcha" />
                                <InputError message={errors.captcha || errors['g-recaptcha-response']} className="mt-2" />
                            </div>
                        )}
                        <PrimaryButton className="w-full justify-center rounded-xl px-5 py-3 text-base tracking-[0.2em]" disabled={processing || (usesRecaptcha && (!captchaReady || !data['g-recaptcha-response']))}>
                            LOG IN
                        </PrimaryButton>
                    </div>
                </form>
            </div>
        </GuestLayout>
    );
}
