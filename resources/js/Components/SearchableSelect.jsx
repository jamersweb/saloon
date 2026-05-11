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
}) {
    const [query, setQuery] = useState('');

    const selectedOption = useMemo(
        () => options.find((option) => String(option.value) === String(value)) || null,
        [options, value],
    );

    const filteredOptions = useMemo(() => {
        const needle = query.trim().toLowerCase();
        if (!needle) return options;
        return options.filter((option) => String(option.label || '').toLowerCase().includes(needle));
    }, [options, query]);

    return (
        <div className={className}>
            {label ? <label className="ta-field-label">{label}</label> : null}
            <input
                className="ta-input mb-2 font-medium text-slate-800 placeholder:font-semibold placeholder:text-slate-600"
                value={query}
                onChange={(e) => setQuery(e.target.value)}
                placeholder={selectedOption ? `Selected: ${selectedOption.label}` : placeholder}
                disabled={disabled}
            />
            <div className={`max-h-48 overflow-y-auto rounded-xl border border-slate-200 bg-white ${disabled ? 'opacity-60' : ''}`}>
                {filteredOptions.length > 0 ? filteredOptions.map((option) => {
                    const selected = String(option.value) === String(value);
                    return (
                        <button
                            key={option.value}
                            type="button"
                        className={`flex w-full items-center justify-between px-3 py-2 text-left text-sm font-medium ${selected ? 'bg-amber-50 text-amber-900' : 'text-slate-800 hover:bg-slate-50'}`}
                            onClick={() => {
                                if (disabled) return;
                                onChange(option.value);
                                setQuery('');
                            }}
                            disabled={disabled}
                        >
                            <span>{option.label}</span>
                            {selected ? <span className="text-[10px] font-bold uppercase tracking-wide text-amber-900">Selected</span> : null}
                        </button>
                    );
                }) : (
                    <div className="px-3 py-2 text-sm font-medium text-slate-700">{emptyLabel}</div>
                )}
            </div>
        </div>
    );
}
