(() => {
    'use strict';
    const config = JSON.parse(document.getElementById('booking-data').textContent);
    const $ = (id) => document.getElementById(id);
    const services = config.services;
    const people = [new Set()];
    let activeGuest = 0;
    let selected = people[0];
    let group = false;
    let professional = null;
    let step = 'visit';
    const guestName = index => index === 0 ? 'Me' : 'Guest ' + (index + 1);
    const complete = () => people.every(person => person.size > 0);
    const selectionPayload = () => group
        ? {guests: people.map(person => [...person])}
        : {service_ids: [...people[0]]};
    const money = (minor) => `AED ${(minor / 100).toFixed(2)}`;
    const categoryOf = (s) => {
        const value = (s.category || '').toLowerCase();
        if (/combo|package|offer/.test(value)) return 'Packages & offers';
        if (/hair/.test(value)) return 'Hair';
        if (/nail|hand|foot/.test(value)) return 'Nails';
        if (/brow|lash|micro blad|thread/.test(value)) return 'Lash & Brow';
        if (/face|skin/.test(value)) return 'Skincare';
        if (/wax/.test(value)) return 'Waxing';
        if (/makeup/.test(value)) return 'Makeup';
        return 'Other';
    };
    const categories = ['Hair', 'Nails', 'Skincare', 'Lash & Brow', 'Waxing', 'Makeup', 'Packages & offers', 'Other'].filter((category) => services.some((s) => categoryOf(s) === category));
    let category = categories[0], slot = null, controller = null, loading = false, paying = false, visibleCount = 6;
    const node = (tag, className, text) => { const n = document.createElement(tag); if (className) n.className = className; if (text !== undefined) n.textContent = text; return n; };
    const chosen = () => people.flatMap((person, guest) => services.filter(s => person.has(s.id)).map(s => ({...s, guest})));
    const totals = () => { const list = chosen(); const total = list.reduce((n, s) => n + Math.round(Number(s.price) * 100), 0); return {total, deposit: Math.round(total * .15), duration: list.reduce((n, s) => n + Number(s.duration_minutes) + Number(s.buffer_minutes || 0), 0)}; };
    function render() {
        $('categories').replaceChildren(...categories.map((name) => {
            const button = node('button', 'category', name); button.type = 'button'; button.setAttribute('aria-pressed', String(name === category));
            button.onclick = () => { category = name; visibleCount = 6; render(); }; return button;
        }));
        const categoryServices = services.filter((s) => categoryOf(s) === category);
        $('category-title').textContent = category || 'Services';
        $('category-count').textContent = categoryServices.length + (categoryServices.length === 1 ? ' treatment' : ' treatments');
        $('more-services').hidden = categoryServices.length <= visibleCount;
        $('more-services').textContent = `Show more services (${Math.max(0, categoryServices.length - visibleCount)} remaining)`;
        $('service-list').replaceChildren(...categoryServices.slice(0, visibleCount).map((s) => {
            const button = node('button', 'service-row'); button.type = 'button'; button.setAttribute('aria-pressed', String(selected.has(s.id)));
            button.setAttribute('aria-label', `${selected.has(s.id) ? 'Remove' : 'Add'} ${s.name}, ${s.duration_minutes} minutes, ${money(Math.round(Number(s.price) * 100))}`);
            button.append(node('span', 'service-name', s.name), node('span', 'service-duration', `${s.duration_minutes} min`), node('span', 'service-price', money(Math.round(Number(s.price) * 100))), node('span', 'service-toggle', selected.has(s.id) ? '✓' : '+'));
            if (Number(s.price) <= 0 || Number(s.duration_minutes) <= 0) {
                button.disabled = true; button.querySelector('.service-price').textContent = 'Contact salon'; button.querySelector('.service-toggle').textContent = '–';
            }
            button.onclick = () => { if (selected.has(s.id)) selected.delete(s.id); else selected.add(s.id); render(); loadSlots(); };
            return button;
        }));
        if (!services.length) $('service-list').append(node('p', 'empty', 'Services are currently unavailable. Please contact the salon.'));
        $('summary-services').replaceChildren(...chosen().map((s) => {
            const row = node('div', 'summary-service'), info = node('div'), remove = node('button', 'summary-remove', 'Remove'); remove.type = 'button'; remove.setAttribute('aria-label', `Remove ${s.name}`);
            if (group) info.append(node('small', '', guestName(s.guest)));
            info.append(node('strong', '', s.name), node('small', '', `${s.duration_minutes} min${Number(s.buffer_minutes) ? ` + ${s.buffer_minutes} min preparation` : ''}`));
            remove.onclick = () => {
                people[s.guest].delete(s.id);
                editGuest(s.guest);
                loadSlots();
            };
            if (step === 'services' || step === 'guests') info.append(remove);
            row.append(info, node('span', '', money(Math.round(Number(s.price) * 100)))); return row;
        }));
        if (!chosen().length) $('summary-services').append(node('p', 'muted', 'Select a service to begin.'));
        const {total, deposit, duration} = totals();
        $('total-price').textContent = money(total); $('deposit-price').textContent = money(deposit); $('balance-price').textContent = money(total - deposit); $('total-duration').textContent = `${duration} min`;
        $('pay-button').textContent = paying ? 'Opening secure payment…' : `Pay ${money(deposit)} deposit`;
        $('pay-button').disabled = !slot || !selected.size || paying || loading;
        const next = $('continue-button');
        next.hidden = step === 'details';
        next.textContent = step === 'services' && group ? 'Save guest services' : step === 'schedule' ? 'Continue to your details' : step === 'professional' ? 'Choose date & time' : 'Select professional';
        next.disabled = step === 'services' ? !selected.size : step === 'schedule' ? !slot || loading : step === 'professional' ? professional === null : !complete();
        $('visit-options').hidden = step !== 'visit';
        $('previous-step').hidden = step === 'visit';
        $('guest-roster').hidden = step !== 'guests';
        $('categories').hidden = $('services').hidden = step !== 'services';
        $('professionals').hidden = step !== 'professional';
        $('summary-professional').hidden = !professional || !['professional', 'schedule', 'details'].includes(step);
        $('summary-professional').textContent = professional?.dataset.name || '';
        $('schedule').hidden = step !== 'schedule';
        $('details').hidden = step !== 'details';
        document.querySelector('.summary').hidden = step === 'visit';
        document.querySelector('.studio-intro').hidden = step !== 'visit';
        $('editing-guest').hidden = !group || step !== 'services';
        $('editing-guest').textContent = 'Services for ' + guestName(activeGuest);
        $('step-label').textContent = {visit:'',services:'Services',guests:'Your group',professional:'Professional',schedule:'Date & time',details:'Your details'}[step];
        if (step === 'guests') renderGuests();
    }
    async function loadSlots() {
        controller?.abort(); controller = new AbortController(); const active = controller;
        slot = null; $('slots').replaceChildren(); $('selected-time').textContent = ''; loading = true; render();
        if (step !== 'schedule') {
            loading = false;
            render();
            return;
        }
        if (!complete() || !$('booking-date').value) { loading = false; $('slot-status').textContent = 'Select your services and a date to see available times.'; render(); return; }
        $('slot-status').textContent = 'Finding available appointments…';
        const query = new URLSearchParams({date: $('booking-date').value});
        if (professional?.dataset.professional) query.set('staff_id', professional.dataset.professional);
        people.forEach((person, guest) => person.forEach(id => {
            query.append(group ? 'guests[' + guest + '][]' : 'service_ids[]', id);
        }));
        try {
            const response = await fetch(`${config.availabilityUrl}?${query}`, {headers: {Accept: 'application/json'}, signal: active.signal});
            const data = await response.json(); if (!response.ok) throw new Error(data.message || 'Availability could not be loaded.');
            $('slot-status').textContent = data.slots.length ? 'Choose a time. Outlined appointments fit directly into the staff schedule.' : 'No appointments fit on this date. Please choose another day.';
            const recommended = data.slots.filter((s) => s.recommended);
            $('slots').replaceChildren(...data.slots.map((s) => {
                const button = node('button', `slot${s.recommended ? ' recommended' : ''}`, s.label); button.type = 'button'; button.setAttribute('aria-pressed', 'false');
                button.setAttribute('aria-label', `${s.label} to ${s.end_label}${s.recommended ? ', recommended' : ''}`);
                button.onclick = () => { slot = s; $('slots').querySelectorAll('button').forEach((b) => b.setAttribute('aria-pressed', String(b === button))); $('selected-time').textContent = `${s.label} – ${s.end_label} · ${totals().duration} minutes, including preparation`; render(); }; return button;
            }));
            // Prioritize the earliest consecutive slot while leaving every available time selectable.
            const next = recommended[0] || data.slots[0];
            if (next) $('slots').children[data.slots.indexOf(next)].click();
        } catch (error) { if (error.name !== 'AbortError') $('slot-status').textContent = error.message; }
        finally { if (active === controller) { loading = false; render(); } }
    }
    $('booking-date').addEventListener('change', loadSlots);
    $('more-services').onclick = () => { visibleCount += 8; render(); };
    function navigate(destination) {
        step = destination;
        render();
        window.scrollTo(0, 0);
    }
    function editGuest(index) {
        activeGuest = index;
        selected = people[index];
        navigate('services');
    }
    function renderGuests() {
        $('guest-list').replaceChildren(...people.map((person, index) => {
            const row = node('div', 'guest-line');
            const edit = node('button', '', guestName(index));
            edit.type = 'button';
            edit.append(node('small', '', services.filter(service => person.has(service.id)).map(service => service.name).join(', ') || 'Choose services'));
            edit.onclick = () => editGuest(index);
            row.append(edit);
            if (index > 0 && people.length > 2) {
                const remove = node('button', 'guest-remove', 'Remove');
                remove.onclick = () => {
                    people.splice(index, 1);
                    activeGuest = 0;
                    selected = people[0];
                    loadSlots();
                };
                row.append(remove);
            }
            return row;
        }));
        $('add-guest').disabled = people.length >= 15;
    }
    document.querySelectorAll('[data-visit]').forEach(button => {
        button.onclick = () => {
            const wantsGroup = button.dataset.visit === 'group';
            if (!wantsGroup && people.slice(1).some(person => person.size) &&
                !window.confirm('Switch to an individual appointment and remove the other guests?')) return;
            group = wantsGroup;
            if (!group) people.splice(1);
            else if (people.length === 1) people.push(new Set());
            slot = null;
            editGuest(0);
            loadSlots();
        };
    });
    $('add-guest').onclick = () => {
        if (people.length >= 15) return;
        people.push(new Set());
        editGuest(people.length - 1);
        loadSlots();
    };
    const professionalButtons = document.querySelectorAll('[data-professional]');
    professionalButtons.forEach(button => {
        button.onclick = () => {
            professional = button;
            slot = null;
            professionalButtons.forEach(option => {
                option.setAttribute('aria-pressed', String(option === button));
                option.textContent = option === button ? 'Selected' : 'Select';
            });
            render();
        };
    });
    $('previous-step').onclick = () => {
        if (step === 'services') navigate(group && activeGuest > 0 ? 'guests' : 'visit');
        else if (step === 'guests') editGuest(0);
        else if (step === 'schedule') navigate('professional');
        else if (step === 'professional') navigate(group ? 'guests' : 'services');
        else navigate('schedule');
    };
    $('continue-button').onclick = () => {
        if (step === 'schedule') return navigate('details');
        if (group && step === 'services') return navigate('guests');
        if (step !== 'professional') return navigate('professional');
        navigate('schedule');
        loadSlots();
    };
    $('booking-form').addEventListener('submit', async (event) => {
        event.preventDefault(); if (!slot || paying) return;
        const errorBox = $('booking-error'); errorBox.hidden = true; paying = true; render();
        try {
            const payload = {...Object.fromEntries(new FormData(event.target)), ...selectionPayload(), start: slot.start, staff_id: slot.staff_id};
            const response = await fetch(config.checkoutUrl, {method: 'POST', headers: {'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content}, body: JSON.stringify(payload)});
            const data = await response.json();
            if (!response.ok) throw new Error(response.status === 419 ? 'Please open booking in a new tab using the link below, then try again.' : Object.values(data.errors || {}).flat().join(' ') || data.message || 'Could not complete booking. Please try again.');
            // Payment providers may prohibit iframe rendering; navigate the top-level page.
            try { window.top.location.href = data.checkout_url; } catch { window.location.href = data.checkout_url; }
        } catch (error) { errorBox.textContent = error.message; errorBox.hidden = false; errorBox.scrollIntoView({block: 'center'}); }
        finally { paying = false; render(); }
    });
    render();
})();
