document.addEventListener('DOMContentLoaded', () => {
    const modal = document.getElementById('import-progress-modal');
    if (!modal) return;

    const dialog = modal.querySelector('.verify-dialog');
    const track = modal.querySelector('.verify-progress-track');
    const fill = document.getElementById('import-progress-fill');
    const hint = document.getElementById('import-progress-hint');
    const stages = ['queued', 'connecting', 'fetching', 'importing', 'analyzing', 'finished'];
    const stageDefaults = new Map([...modal.querySelectorAll('[data-stage]')].map((item) => [item.dataset.stage, item.querySelector('small').textContent]));
    const number = (value) => new Intl.NumberFormat('ro-RO').format(value ?? 0);
    const elapsed = (started, finished) => {
        const duration = Math.max(0, new Date(finished ?? Date.now()).getTime() - new Date(started).getTime());
        return duration < 1000 ? '<1 s' : `${Math.round(duration / 1000)} s`;
    };
    const put = (id, value) => { document.getElementById(id).textContent = value; };
    let timer = null;
    let activeRun = null;
    let completed = false;
    let previousFocus = null;
    let openedAt = 0;

    function close() {
        modal.hidden = true;
        clearTimeout(timer);
        activeRun = null;
        previousFocus?.focus();
        if (completed) window.location.reload();
    }

    function open(name, runId) {
        previousFocus = document.activeElement;
        activeRun = runId;
        completed = false;
        openedAt = Date.now();
        hint.hidden = true;
        modal.hidden = false;
        put('import-progress-title', name);
        put('import-progress-step', 'Se așteaptă prima actualizare…');
        put('import-progress-status', 'În curs');
        for (const id of ['host', 'http', 'bytes']) put(`import-progress-${id}`, '—');
        modal.querySelectorAll('[data-stage]').forEach((item) => {
            item.classList.remove('done', 'active', 'skipped');
            item.querySelector('small').textContent = stageDefaults.get(item.dataset.stage);
            item.querySelector('time').textContent = '—';
        });
        dialog.focus();
    }

    function render(data) {
        put('import-progress-title', data.name);
        put('import-progress-status', data.status_label);
        put('import-progress-step', data.message);
        put('import-progress-duration', `${Math.max(0, Math.round((data.duration_ms ?? 0) / 1000))} s`);
        put('import-progress-processed', `${number(data.processed)} / ${data.detected == null ? '—' : number(data.detected)}`);
        put('import-progress-host', data.source_host || '—');
        put('import-progress-http', data.http_status ?? '—');
        put('import-progress-bytes', data.response_bytes == null ? '—' : `${number(data.response_bytes)} B`);
        for (const key of ['database', 'detected', 'new', 'updated', 'unchanged', 'errors']) {
            put(`import-progress-${key}`, data[key] == null ? '—' : number(data[key]));
        }
        const stageIndex = stages.indexOf(data.stage);
        modal.querySelectorAll('[data-stage]').forEach((item) => {
            const index = stages.indexOf(item.dataset.stage);
            const step = data.steps?.[item.dataset.stage];
            const finished = data.stage === 'finished' && data.status !== 'checking';
            item.classList.toggle('done', !!step && (index < stageIndex || finished));
            item.classList.toggle('active', !!step && index === stageIndex && !finished);
            item.classList.toggle('skipped', !step && finished);
            if (step) {
                item.querySelector('small').textContent = step.message;
                item.querySelector('time').textContent = elapsed(step.started_at, step.finished_at ?? (finished ? data.checked_at_iso : null));
            } else if (finished) {
                item.querySelector('small').textContent = 'Nu s-a aplicat acestei surse';
                item.querySelector('time').textContent = '—';
            }
        });
        if (data.detected > 0) {
            const percent = Math.min(100, Math.round(data.processed / data.detected * 100));
            fill.style.width = `${percent}%`;
            track.setAttribute('aria-valuenow', String(percent));
            track.setAttribute('aria-valuetext', `${number(data.processed)} din ${number(data.detected)} înregistrări procesate`);
            track.classList.remove('indeterminate');
        } else {
            fill.style.width = data.stage === 'finished' ? '100%' : '35%';
            track.classList.toggle('indeterminate', data.stage !== 'finished');
            track.setAttribute('aria-valuetext', data.stage === 'finished' ? 'Finalizat' : 'Progres nedeterminabil încă');
        }
        hint.hidden = !(data.stage === 'queued' && Date.now() - openedAt > 20000);
        if (data.stage === 'finished' || data.status !== 'checking') {
            completed = true;
            clearTimeout(timer);
        }
    }

    async function poll(url, runId) {
        if (activeRun !== runId) return;
        try {
            const response = await fetch(url, { headers: { Accept: 'application/json' }, cache: 'no-store' });
            if (!response.ok) throw new Error('Progresul nu poate fi citit momentan.');
            const data = await response.json();
            if (data.run_id !== runId) throw new Error('A început o altă verificare pentru această sursă. Reîncarcă pagina.');
            render(data);
            if (!completed && activeRun === runId) timer = setTimeout(() => poll(url, runId), 1200);
        } catch (error) {
            put('import-progress-step', error.message);
            if (activeRun === runId) timer = setTimeout(() => poll(url, runId), 3000);
        }
    }

    document.querySelectorAll('[data-verify-source]').forEach((form) => form.addEventListener('submit', async (event) => {
        event.preventDefault();
        const button = form.querySelector('button[type="submit"]');
        button.disabled = true;
        open(form.closest('tr')?.querySelector('.source-name b')?.textContent ?? 'Sursă de date', null);
        try {
            const response = await fetch(form.action, {
                method: 'POST', body: new FormData(form),
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            });
            const data = await response.json();
            if (!response.ok) throw new Error(data.message ?? 'Verificarea nu a putut fi pornită.');
            activeRun = data.run_id;
            poll(data.progress_url, data.run_id);
        } catch (error) {
            put('import-progress-step', error.message);
            button.disabled = false;
        }
    }));

    document.querySelectorAll('[data-resume-progress]').forEach((button) => button.addEventListener('click', () => {
        open(button.closest('tr')?.querySelector('.source-name b')?.textContent ?? 'Sursă de date', button.dataset.runId);
        poll(button.dataset.resumeProgress, button.dataset.runId);
    }));
    modal.querySelectorAll('[data-close-progress]').forEach((button) => button.addEventListener('click', close));
    document.addEventListener('keydown', (event) => { if (!modal.hidden && event.key === 'Escape') close(); });
});
