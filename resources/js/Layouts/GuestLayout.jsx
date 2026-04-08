import ApplicationLogo from '@/Components/ApplicationLogo';
import AppFlashPopup from '@/Components/AppFlashPopup';
import { Link } from '@inertiajs/react';

export default function GuestLayout({ children }) {
    return (
        <div className="flex min-h-screen flex-col items-center bg-slate-100 pt-6 sm:justify-center sm:pt-0">
            <AppFlashPopup />
            <div className="mb-3 text-center">
                <Link href="/">
                    <ApplicationLogo className="mx-auto h-auto w-64" />
                </Link>
            </div>

            <div className="ta-card mt-6 w-full overflow-hidden bg-white px-6 py-4 sm:max-w-md sm:rounded-lg">
                {children}
            </div>
        </div>
    );
}
