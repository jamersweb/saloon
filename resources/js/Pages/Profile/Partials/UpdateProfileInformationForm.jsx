import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import PrimaryButton from '@/Components/PrimaryButton';
import TextInput from '@/Components/TextInput';
import { Transition } from '@headlessui/react';
import { Link, useForm, usePage } from '@inertiajs/react';
import { useRef, useState } from 'react';

export default function UpdateProfileInformation({
    mustVerifyEmail,
    status,
    className = '',
}) {
    const user = usePage().props.auth.user;
    const fileInputRef = useRef(null);
    const [previewUrl, setPreviewUrl] = useState(null);

    const { data, setData, patch, errors, processing, recentlySuccessful } =
        useForm({
            name: user.name,
            email: user.email,
            profile_image: null,
            remove_profile_image: false,
        });

    const submit = (e) => {
        e.preventDefault();

        patch(route('profile.update'), {
            forceFormData: true,
            onSuccess: () => {
                setPreviewUrl(null);
                setData('profile_image', null);
                setData('remove_profile_image', false);
                if (fileInputRef.current) {
                    fileInputRef.current.value = '';
                }
            },
        });
    };

    const currentImageUrl = previewUrl || user.profile_image_url;

    return (
        <section className={className}>
            <header>
                <h2 className="text-lg font-medium text-gray-900">
                    Profile Information
                </h2>

                <p className="mt-1 text-sm text-gray-600">
                    Update your account's profile information and email address.
                </p>
            </header>

            <form onSubmit={submit} className="mt-6 space-y-6">
                <div>
                    <InputLabel htmlFor="profile_image" value="Profile Image" />
                    <div className="mt-2 flex items-center gap-4">
                        {currentImageUrl ? (
                            <img
                                src={currentImageUrl}
                                alt="Profile preview"
                                className="h-16 w-16 rounded-full object-cover ring-2 ring-slate-200"
                            />
                        ) : (
                            <div className="flex h-16 w-16 items-center justify-center rounded-full bg-slate-200 text-xs font-semibold text-slate-500">
                                No image
                            </div>
                        )}
                        <div className="flex-1 space-y-2">
                            <input
                                ref={fileInputRef}
                                id="profile_image"
                                type="file"
                                accept=".jpg,.jpeg,.png,.webp"
                                className="ta-input"
                                onChange={(e) => {
                                    const file = e.target.files?.[0] ?? null;
                                    setData('profile_image', file);
                                    setData('remove_profile_image', false);
                                    if (file) {
                                        setPreviewUrl(URL.createObjectURL(file));
                                    } else {
                                        setPreviewUrl(null);
                                    }
                                }}
                            />
                            {currentImageUrl && (
                                <button
                                    type="button"
                                    className="text-xs font-medium text-red-600 underline underline-offset-4"
                                    onClick={() => {
                                        setPreviewUrl(null);
                                        setData('profile_image', null);
                                        setData('remove_profile_image', true);
                                        if (fileInputRef.current) {
                                            fileInputRef.current.value = '';
                                        }
                                    }}
                                >
                                    Remove image
                                </button>
                            )}
                        </div>
                    </div>
                    <InputError className="mt-2" message={errors.profile_image} />
                </div>

                <div>
                    <InputLabel htmlFor="name" value="Name" />

                    <TextInput
                        id="name"
                        className="mt-1 block w-full"
                        value={data.name}
                        onChange={(e) => setData('name', e.target.value)}
                        required
                        isFocused
                        autoComplete="name"
                    />

                    <InputError className="mt-2" message={errors.name} />
                </div>

                <div>
                    <InputLabel htmlFor="email" value="Email" />

                    <TextInput
                        id="email"
                        type="email"
                        className="mt-1 block w-full"
                        value={data.email}
                        onChange={(e) => setData('email', e.target.value)}
                        required
                        autoComplete="username"
                    />

                    <InputError className="mt-2" message={errors.email} />
                </div>

                {mustVerifyEmail && user.email_verified_at === null && (
                    <div>
                        <p className="mt-2 text-sm text-gray-800">
                            Your email address is unverified.
                            <Link
                                href={route('verification.send')}
                                method="post"
                                as="button"
                                className="rounded-md text-sm text-gray-600 underline hover:text-gray-900 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2"
                            >
                                Click here to re-send the verification email.
                            </Link>
                        </p>

                        {status === 'verification-link-sent' && (
                            <div className="mt-2 text-sm font-medium text-green-600">
                                A new verification link has been sent to your
                                email address.
                            </div>
                        )}
                    </div>
                )}

                <div className="flex items-center gap-4">
                    <PrimaryButton disabled={processing}>Save</PrimaryButton>

                    <Transition
                        show={recentlySuccessful}
                        enter="transition ease-in-out"
                        enterFrom="opacity-0"
                        leave="transition ease-in-out"
                        leaveTo="opacity-0"
                    >
                        <p className="text-sm text-gray-600">
                            Saved.
                        </p>
                    </Transition>
                </div>
            </form>
        </section>
    );
}
