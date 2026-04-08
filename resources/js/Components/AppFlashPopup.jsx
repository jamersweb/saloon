import { usePage } from '@inertiajs/react';
import { useEffect, useMemo, useState } from 'react';

export default function AppFlashPopup() {
    const { flash, errors } = usePage().props;
    const [visible, setVisible] = useState(false);

    const message = useMemo(() => {
        if (flash?.status) {
            return { type: 'success', text: flash.status };
        }

        const firstError = Object.values(errors || {})[0];
        if (firstError) {
            return { type: 'error', text: String(firstError) };
        }

        return null;
    }, [errors, flash?.status]);

    useEffect(() => {
        if (!message) {
            setVisible(false);
            return undefined;
        }

        setVisible(true);
        const timer = window.setTimeout(() => setVisible(false), 4200);

        return () => window.clearTimeout(timer);
    }, [message]);

    if (!message || !visible) {
        return null;
    }

    return (
        <div className="pointer-events-none fixed right-4 top-4 z-[70]">
            <div
                className={`pointer-events-auto min-w-[280px] max-w-[460px] rounded-xl border px-4 py-3 text-sm shadow-lg ${
                    message.type === 'error'
                        ? 'border-red-200 bg-red-50 text-red-700'
                        : 'border-emerald-200 bg-emerald-50 text-emerald-700'
                }`}
            >
                <div className="flex items-start justify-between gap-3">
                    <p>{message.text}</p>
                    <button
                        type="button"
                        className="text-xs font-semibold opacity-80 hover:opacity-100"
                        onClick={() => setVisible(false)}
                    >
                        Close
                    </button>
                </div>
            </div>
        </div>
    );
}

