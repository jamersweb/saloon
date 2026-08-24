import { useMemo, useState } from 'react';

export default function SearchableSelect({
    label,
    value,
    onChange,
    options = [],
    placeholder = 'Search and select',
    emptyLabel = 'No matches found',
    disabled = false,
    className = '',
    variant = 'light',
}) {
    const [query, setQuery] = useState('');
    const [isOpen, setIsOpen] = useState(false);

    const selectedOption = useMemo(
        () => options.find((option) => String(option.value) === String(value)) || null,
        [options, value],
    );

    const filteredOptions = useMemo(() => {
        const needle = query.trim().toLowerCase();
        if (!needle) return options;
        return options.filter((option) => String(option.label || '').toLowerCase().includes(needle));
    }, [options, query]);

    const isDark = variant === 'dark';
    const displayValue = isOpen ? query : (selectedOption?.label ?? '');

    return (
        <div className={`relative ${className}`}>
            {label ? <label className="ta-field-label">{label}</label> : null}
            <input
                className={`ta-input font-medium placeholder:font-semibold ${isDark ? 'text-white placeholder:text-slate-500' : 'text-slate-800 placeholder:text-slate-600'}`}
                value={displayValue}
                onBlur={() => setTimeout(() => setIsOpen(false), 100)}
                onChange={(e) => {
                    setQuery(e.target.value);
                    setIsOpen(true);
                }}
                onFocus={() => setIsOpen(true)}
                placeholder={placeholder}
                disabled={disabled}
            />
            {isOpen && !disabled ? (
                <div className={`absolute left-0 right-0 top-full z-30 mt-1 max-h-56 overflow-y-auto border shadow-lg ${isDark ? 'rounded-md border-white/15 bg-[#18181a]' : 'rounded-xl border-slate-200 bg-white'}`}>
                    {filteredOptions.length > 0 ? filteredOptions.map((option) => {
                        const selected = String(option.value) === String(value);

                        return (
                            <button
                                key={option.value}
                                type="button"
                                className={`flex w-full items-center justify-between gap-3 px-3 py-2 text-left text-sm font-medium ${isDark
                                    ? (selected ? 'bg-violet-500/15 text-violet-100' : 'text-slate-200 hover:bg-white/5')
                                    : (selected ? 'bg-amber-50 text-amber-900' : 'text-slate-800 hover:bg-slate-50')}`}
                                onMouseDown={(event) => {
                                    event.preventDefault();
                                    onChange(option.value);
                                    setQuery('');
                                    setIsOpen(false);
                                }}
                            >
                                <span className="min-w-0 truncate">{option.label}</span>
                                {selected ? <span className={`shrink-0 text-[10px] font-bold uppercase tracking-wide ${isDark ? 'text-violet-200' : 'text-amber-900'}`}>Selected</span> : null}
                            </button>
                        );
                    }) : (
                        <div className={`px-3 py-2 text-sm font-medium ${isDark ? 'text-slate-400' : 'text-slate-700'}`}>{emptyLabel}</div>
                    )}
                </div>
            ) : null}
        </div>
    );
}
