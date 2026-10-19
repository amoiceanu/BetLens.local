const form = document.querySelector('[data-upcoming-import]');
const modal = document.getElementById('upcoming-import-modal');

if (form && modal) {
    const dialog = modal.querySelector('.upcoming-import-dialog');
    const button = form.querySelector('button[type="submit"]');
    const close = modal.querySelector('[data-upcoming-close]');
    const put = (key, value) => { document.getElementById(`upcoming-import-${key}`).textContent = value; };
    let timer;
    let active = false;

    close.addEventListener('click', () => { modal.hidden = true; dialog.blur(); button.focus(); });
    modal.addEventListener('click', (event) => { if (event.target === modal) close.click(); });
    document.addEventListener('keydown', (event) => { if (event.key === 'Escape' && !modal.hidden) close.click(); });

    async function poll(url) {
        try {
            const response = await fetch(url, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
            if (!response.ok) throw new Error(`HTTP ${response.status}`);
            const run = await response.json();
            const meta = run.metadata ?? {};
            const stage = ['completed', 'partial', 'failed'].includes(run.status) ? 'finished' : (meta.stage ?? 'queued');
            put('message', run.message ?? 'Se așteaptă actualizarea…');
            put('status-detail', run.status === 'queued' ? 'Un worker Laravel va procesa importul în curând.'
                : run.status === 'running' ? 'Datele sunt preluate și salvate în baza de date.'
                    : run.status === 'completed' ? 'Toate ligile disponibile au fost procesate.'
                        : run.status === 'partial' ? 'Unele ligi au întâmpinat erori; vezi detaliile de mai jos.'
                            : 'Verifică logurile serverului pentru detalii.');
            put('league', meta.league ?? '—');
            put('league-detail', meta.league ? 'Se procesează datele acestei ligi.' : 'Nu a fost încă selectată o ligă.');
            put('leagues', meta.leagues_done ?? 0);
            put('read', run.records_fetched ?? 0);
            put('new', run.records_new ?? 0);
            put('updated', run.records_updated ?? 0);
            put('unchanged', run.records_skipped ?? 0);
            put('predictions', meta.predictions_new ?? 0);
            put('errors', run.records_errors ?? 0);
            const errors = document.getElementById('upcoming-import-error-list');
            errors.hidden = !meta.errors?.length;
            errors.textContent = meta.errors?.join(' · ') ?? '';
            modal.querySelectorAll('[data-import-stage]').forEach((item) => {
                const order = ['queued', 'connecting', 'writing', 'finished'];
                const current = stage === 'league_done' || stage === 'league_error' ? 'writing' : stage;
                item.classList.toggle('is-active', item.dataset.importStage === current);
                item.classList.toggle('is-done', order.indexOf(item.dataset.importStage) < order.indexOf(current));
            });
            if (['completed', 'partial', 'failed'].includes(run.status)) {
                active = false;
                button.disabled = false;
                return;
            }
        } catch {
            put('message', 'Nu s-a putut citi progresul. Se reîncearcă automat.');
        }
        timer = window.setTimeout(() => poll(url), 2000);
    }

    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        if (active) return;
        active = true;
        button.disabled = true;
        modal.hidden = false;
        dialog.focus();
        put('message', 'Importul este trimis în coadă…');
        put('status-detail', 'Un worker Laravel va procesa importul în curând.');
        try {
            const response = await fetch(form.action, { method: 'POST', body: new FormData(form),
                headers: { Accept: 'application/json' }, credentials: 'same-origin' });
            if (!response.ok) throw new Error(`HTTP ${response.status}`);
            const result = await response.json();
            window.clearTimeout(timer);
            poll(result.progress_url);
        } catch (error) {
            put('message', `Importul nu a putut porni (${error.message}).`);
            active = false;
            button.disabled = false;
        }
    });
}
