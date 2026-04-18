import Modal from '@/Components/Modal';

/**
 * Standard confirmation dialog (e.g. delete / deactivate). Uses the same overlay as other modals.
 */
export default function ConfirmActionModal({
    show = false,
    onClose = () => {},
    title = 'Are you sure?',
    message = 'This action cannot be undone.',
    confirmText = 'Delete',
    cancelText = 'Cancel',
    /** Tailwind classes for the primary action button */
    confirmClassName = 'rounded-xl bg-red-600 px-4 py-2 text-sm font-semibold text-white hover:bg-red-700 disabled:opacity-60',
    onConfirm = () => {},
    processing = false,
}) {
    return (
        <Modal show={show} onClose={onClose} maxWidth="md" closeable={!processing}>
            <div className="p-6">
                <h3 className="text-lg font-semibold text-slate-900">{title}</h3>
                <p className="mt-2 text-sm leading-relaxed text-slate-600">{message}</p>
                <div className="mt-6 flex flex-wrap justify-end gap-2">
                    <button
                        type="button"
                        className="rounded-xl border border-slate-200 bg-white px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50 disabled:opacity-50"
                        onClick={onClose}
                        disabled={processing}
                    >
                        {cancelText}
                    </button>
                    <button type="button" className={confirmClassName} onClick={onConfirm} disabled={processing}>
                        {processing ? 'Please wait…' : confirmText}
                    </button>
                </div>
            </div>
        </Modal>
    );
}
