export default function TablePagination({
    page,
    totalPages,
    totalItems,
    pageSize,
    onPageChange,
    itemLabel = 'rows',
}) {
    const safePage = Math.max(1, Number(page || 1));
    const safeTotalPages = Math.max(1, Number(totalPages || 1));
    const safeTotalItems = Math.max(0, Number(totalItems || 0));
    const safePageSize = Math.max(1, Number(pageSize || 1));
    const from = safeTotalItems === 0 ? 0 : ((safePage - 1) * safePageSize) + 1;
    const to = Math.min(safeTotalItems, safePage * safePageSize);

    return (
        <div className="flex flex-col gap-2 border-t border-slate-200 px-5 py-3 text-xs text-slate-600 sm:flex-row sm:items-center sm:justify-between">
            <span>
                Showing {from}-{to} of {safeTotalItems} {itemLabel}
            </span>
            <div className="flex items-center gap-2">
                <span>Page {safePage} of {safeTotalPages}</span>
                <button
                    type="button"
                    className="rounded-lg border border-slate-200 px-2 py-1 disabled:opacity-50"
                    disabled={safePage <= 1}
                    onClick={() => onPageChange(Math.max(1, safePage - 1))}
                >
                    Previous
                </button>
                <button
                    type="button"
                    className="rounded-lg border border-slate-200 px-2 py-1 disabled:opacity-50"
                    disabled={safePage >= safeTotalPages}
                    onClick={() => onPageChange(Math.min(safeTotalPages, safePage + 1))}
                >
                    Next
                </button>
            </div>
        </div>
    );
}
