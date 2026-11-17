(() => {
    const config = JSON.parse(document.getElementById('reschedule-data').textContent);
    const date = document.getElementById('move-date'), slots = document.getElementById('move-slots'), status = document.getElementById('move-status'), button = document.getElementById('move-button');
    let selection = null, controller = null, submitting = false;
    date.onchange = async () => {
        controller?.abort(); controller = new AbortController();
        selection = null; button.disabled = true; slots.replaceChildren(); if (!date.value) return;
        status.textContent = 'Finding available appointments…';
        try {
            const response = await fetch(`${config.availabilityUrl}?${new URLSearchParams({date: date.value, token: config.token})}`, {headers: {Accept: 'application/json'}, signal: controller.signal});
            const data = await response.json(); if (!response.ok) throw new Error(data.message || 'Could not load times.');
            status.textContent = data.slots.length ? 'Choose a new time.' : 'No appointments fit on this date.';
            data.slots.forEach((slot) => { const b = document.createElement('button'); b.type = 'button'; b.className = 'slot'; b.textContent = `${slot.label} – ${slot.end_label}`; b.setAttribute('aria-pressed', 'false'); b.onclick = () => { selection = slot; slots.querySelectorAll('button').forEach((el) => el.setAttribute('aria-pressed', String(el === b))); button.disabled = false; }; slots.append(b); });
        } catch (error) { if (error.name !== 'AbortError') status.textContent = error.message; }
    };
    button.onclick = async () => {
        if (!selection || submitting) return; submitting = true; button.disabled = true; date.disabled = true;
        try {
            const response = await fetch(config.rescheduleUrl, {method: 'POST', headers: {'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content}, body: JSON.stringify({start: selection.start, staff_id: selection.staff_id})});
            const data = await response.json(); if (!response.ok) throw new Error(Object.values(data.errors || {}).flat().join(' ') || data.message || 'Could not reschedule.');
            status.textContent = data.message; window.location.reload();
        } catch (error) { status.textContent = error.message; submitting = false; button.disabled = false; date.disabled = false; }
    };
})();
