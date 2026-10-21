document.addEventListener('DOMContentLoaded', () => {
    const widget = document.querySelector('[data-master-import]');
    if (!widget) return;

    const startButton = widget.querySelector('[data-master-start]');
    const resumeButton = widget.querySelector('[data-master-resume-url]');
    const recoverButton = widget.querySelector('[data-master-recover]');
    const cancelButton = widget.querySelector('[data-master-cancel]');
    const list = widget.querySelector('[data-master-items]');
    const fill = widget.querySelector('[data-master-fill]');
    const statusLabel = widget.querySelector('[data-master-status-label]');
    const summary = widget.querySelector('[data-master-summary]');
    const currentStep = widget.querySelector('[data-master-current-step]');
    const currentDetail = widget.querySelector('[data-master-current-detail]');
    const elapsedLabel = widget.querySelector('[data-master-elapsed]');
    const lastUpdate = widget.querySelector('[data-master-last-update]');
    const labels = { queued: 'În coadă', running: 'În desfășurare', completed: 'Finalizat', partial: 'Cu avertismente', failed: 'Eșuat', cancelled: 'Oprit', pending: 'În așteptare', skipped: 'Omis' };
    let pollTimer = null;
    let active = ['queued', 'running'].includes(widget.dataset.masterStatus);
    startButton.disabled = active;
    let progressUrl = widget.dataset.progressUrl;
    let startedAt = widget.dataset.masterStatus === 'cancelled'
        ? null
        : (widget.dataset.startedAt ? Date.parse(widget.dataset.startedAt) : null);
    let stoppedAt = null;
    let recoverAfter = 0;

    const duration = milliseconds => {
        const seconds = Math.max(0, Math.floor(milliseconds / 1000));
        const hours = Math.floor(seconds / 3600);
        const minutes = Math.floor((seconds % 3600) / 60);
        const remainder = seconds % 60;
        return hours ? `${String(hours).padStart(2, '0')}:${String(minutes).padStart(2, '0')}:${String(remainder).padStart(2, '0')}` : `${String(minutes).padStart(2, '0')}:${String(remainder).padStart(2, '0')}`;
    };

    const stepStatus = { pending: 'Așteaptă', running: 'În curs', completed: 'Gata' };
    const stepMetricLabels = {
        detected: 'detectate', processed: 'procesate', total: 'total', database: 'în DB', new: 'noi',
        updated: 'actualizate', unchanged: 'neschimbate', errors: 'erori', response_bytes: 'octeți',
        http_status: 'HTTP', leagues_processed: 'ligi', leagues_total: 'total ligi', matches_analyzed: 'meciuri analizate',
        recommendations: 'recomandări', eligible: 'eligibile', teams_new: 'echipe noi', matches_new: 'meciuri noi',
        matches_updated: 'meciuri actualizate', odds_new: 'cote noi', odds_updated: 'cote actualizate', created: 'create',
        records: 'înregistrări',
    };

    function renderTimeline(item) {
        const timeline = document.createElement('div');
        timeline.className = 'master-item-timeline';
        timeline.setAttribute('aria-label', `Etapele importului ${item.name}`);
        for (const step of item.steps ?? []) {
            const card = document.createElement('div');
            card.className = `master-step master-step--${step.status ?? 'pending'}`;
            const heading = document.createElement('div');
            heading.className = 'master-step-heading';
            const icon = document.createElement('span');
            icon.className = 'master-step-icon';
            icon.textContent = step.status === 'completed' ? '✓' : step.status === 'running' ? '↻' : '·';
            const title = document.createElement('strong');
            title.textContent = step.label ?? step.key;
            const state = document.createElement('span');
            state.className = 'master-step-state';
            state.textContent = stepStatus[step.status] ?? step.status ?? 'Așteaptă';
            heading.append(icon, title, state);
            const message = document.createElement('small');
            message.textContent = step.message ?? 'În așteptare';
            card.append(heading, message);
            const metrics = Object.entries(step.counts ?? {}).filter(([key, value]) => value !== null && value !== undefined && stepMetricLabels[key]);
            if (metrics.length) {
                const counts = document.createElement('span');
                counts.className = 'master-step-counts';
                counts.textContent = metrics.map(([key, value]) => `${stepMetricLabels[key]}: ${value}`).join(' · ');
                card.append(counts);
            }
            timeline.append(card);
        }
        return timeline;
    }

    function tick() {
        if (startedAt && Number.isFinite(startedAt)) elapsedLabel.textContent = duration((stoppedAt ?? Date.now()) - startedAt);
        if (recoverButton.hidden === false) recoverButton.disabled = Date.now() < recoverAfter;
    }
    tick();
    window.setInterval(tick, 1000);

    function render(data) {
        widget.dataset.masterStatus = data.status;
        active = ['queued', 'running'].includes(data.status);
        const cancelled = data.status === 'cancelled';
        stoppedAt = active || cancelled ? null : (data.finished_at ? Date.parse(data.finished_at) : (stoppedAt ?? Date.now()));
        statusLabel.textContent = data.status_label;
        if (cancelled) {
            startedAt = null;
            widget.dataset.startedAt = '';
            elapsedLabel.textContent = '00:00';
        } else if (data.started_at) {
            startedAt = Date.parse(data.started_at);
            widget.dataset.startedAt = data.started_at;
            tick();
        }

        const current = data.items.find(item => item.status === 'queued' || item.status === 'running')
            ?? data.items[data.current_index] ?? null;
        summary.textContent = cancelled
            ? `0/${data.total} surse încheiate · rulare oprită; progresul va fi resetat la următoarea pornire`
            : `${data.done}/${data.total} surse încheiate · ${data.completed} reușite · ${data.partial} cu avertismente · ${data.failed} eșuate · ${data.skipped} omise`;
        currentStep.textContent = current ? `${current.name} · ${current.phase_label ?? labels[current.status] ?? current.status}` : (active ? 'Se pregătește următoarea sursă' : 'Nicio sursă activă');
        currentDetail.textContent = data.is_stalled
            ? 'Nu s-a înregistrat progres recent. Verifică dacă workerul Laravel pentru coada default rulează.'
            : (data.current_message ?? current?.message ?? 'Se așteaptă procesarea de către workerul Laravel.');
        lastUpdate.textContent = data.last_activity_at ? `Ultima activitate: ${new Date(data.last_activity_at).toLocaleString('ro-RO')}` : 'Încă nu există activitate înregistrată.';
        recoverButton.hidden = !data.is_stalled;
        widget.classList.toggle('is-stalled', Boolean(data.is_stalled));
        recoverButton.disabled = Date.now() < recoverAfter;

        const currentProgress = current && Number(current.step_total) > 0
            ? Math.min(0.99, Number(current.step_processed) / Number(current.step_total)) : 0;
        const progressTotal = Number(data.progress_total ?? data.total);
        const progressDone = Number(data.progress_done ?? data.done);
        const progressPercent = cancelled
            ? 0
            : (progressTotal
                ? Math.min(100, ((progressDone + currentProgress) / progressTotal) * 100)
                : (data.status === 'completed' ? 100 : 0));
        fill.style.width = `${progressPercent}%`;
        list.replaceChildren();
        for (const item of data.items) {
            const row = document.createElement('li');
            row.className = `master-item master-item--${item.status}`;
            const level = document.createElement('span');
            level.className = 'master-item-level';
            level.textContent = item.level ? `L${item.level}` : '—';
            const name = document.createElement('span');
            name.className = 'master-item-name';
            name.textContent = item.name;
            const state = document.createElement('span');
            state.className = 'master-item-status';
            state.textContent = item.status === 'running' || item.status === 'queued'
                ? (item.phase_label ?? labels[item.status]) : (labels[item.status] ?? item.status);
            const detail = document.createElement('small');
            const counts = `${item.records_fetched ?? 0} citite · ${item.records_new ?? 0} noi · ${item.records_updated ?? 0} actualizate · ${item.records_errors ?? 0} erori`;
            const batch = Number(item.step_total) > 0 ? ` · loturi: ${item.step_processed}/${item.step_total}` : '';
            const itemFinished = ['completed', 'partial', 'failed', 'skipped', 'cancelled'].includes(item.status) && item.finished_at;
            const elapsed = item.started_at ? ` · ${duration((itemFinished ? Date.parse(item.finished_at) : Date.now()) - Date.parse(item.started_at))}` : '';
            detail.textContent = `${item.message ?? 'Așteaptă procesarea.'} · ${counts}${batch}${elapsed}`;
            row.append(level, name, state, detail);
            if (item.steps?.length) row.append(renderTimeline(item));
            if (Number(item.step_total) > 0) {
                const batchProgress = document.createElement('progress');
                batchProgress.className = 'master-item-progress';
                batchProgress.max = Number(item.step_total);
                batchProgress.value = Math.min(Number(item.step_processed), Number(item.step_total));
                batchProgress.setAttribute('aria-label', `${item.name}: lot ${item.step_processed} din ${item.step_total}`);
                row.append(batchProgress);
            }
            list.append(row);
        }

        startButton.disabled = active;
        cancelButton.hidden = !active;
        cancelButton.disabled = false;
        if (data.failed + data.partial > 0 && !resumeButton) {
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'btn secondary';
            button.textContent = 'Reia sursele eșuate';
            button.dataset.masterResumeUrl = `${widget.dataset.startUrl}/${data.id}/resume`;
            button.addEventListener('click', () => submit(button.dataset.masterResumeUrl));
            widget.querySelector('.master-import-actions').append(button);
        }
        if (active) pollTimer = window.setTimeout(poll, 2500);
    }

    async function poll() {
        if (!progressUrl) return;
        try {
            const response = await fetch(progressUrl, { headers: { Accept: 'application/json' }, cache: 'no-store' });
            if (response.status === 429) {
                const retryAfter = Number(response.headers.get('Retry-After'));
                const error = new Error('Solicitările de progres au fost limitate temporar; Master Import continuă în fundal.');
                error.retryDelay = Number.isFinite(retryAfter) && retryAfter > 0 ? Math.min(60000, retryAfter * 1000) : 15000;
                throw error;
            }
            if (!response.ok) throw new Error('Nu am putut citi progresul salvat.');
            render(await response.json());
        } catch (error) {
            summary.textContent = `${error.message} Reîncerc automat.`;
            if (active) pollTimer = window.setTimeout(poll, error.retryDelay ?? 5000);
        }
    }

    async function submit(url) {
        window.clearTimeout(pollTimer);
        stoppedAt = null;
        const isRecovery = url === widget.dataset.recoverUrl;
        if (url === widget.dataset.startUrl) {
            startedAt = null;
            widget.dataset.startedAt = '';
        }
        startButton.disabled = true;
        recoverButton.disabled = true;
        summary.textContent = 'Se trimite solicitarea către coada Laravel…';
        try {
            const response = await fetch(url, { method: 'POST', headers: {
                Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': widget.dataset.csrf,
            } });
            const result = await response.json().catch(() => ({}));
            if (response.status === 429) throw new Error('Prea multe solicitări într-un interval scurt. Așteaptă puțin și încearcă din nou.');
            if (!response.ok) throw new Error(result.message ?? 'Solicitarea nu a putut fi trimisă.');
            progressUrl = result.progress_url;
            widget.dataset.progressUrl = progressUrl;
            if (result.cancel_url) widget.dataset.cancelUrl = result.cancel_url;
            active = true;
            if (isRecovery) recoverAfter = Date.now() + 15000;
            if (!startedAt) startedAt = Date.now();
            await poll();
        } catch (error) {
            startButton.disabled = false;
            recoverButton.disabled = false;
            summary.textContent = error.message;
        }
    }

    async function cancel() {
        if (!active || !widget.dataset.cancelUrl) return;
        if (!window.confirm('Ești sigur că vrei să oprești Master Import? Progresul curent va fi salvat, iar următoarea rulare va începe de la zero. O cerere către o sursă externă deja pornită poate termina înainte ca workerul să se oprească.')) return;
        cancelButton.disabled = true;
        summary.textContent = 'Se salvează progresul și se oprește coordonarea…';
        try {
            const response = await fetch(widget.dataset.cancelUrl, { method: 'POST', headers: {
                Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': widget.dataset.csrf,
            } });
            const result = await response.json();
            if (!response.ok) throw new Error(result.message ?? 'Master Import nu a putut fi oprit.');
            active = false;
            startedAt = null;
            stoppedAt = null;
            widget.dataset.startedAt = '';
            elapsedLabel.textContent = '00:00';
            fill.style.width = '0%';
            widget.dataset.masterStatus = result.status;
            cancelButton.hidden = true;
            startButton.disabled = false;
            await poll();
        } catch (error) {
            cancelButton.disabled = false;
            summary.textContent = error.message;
        }
    }

    startButton.addEventListener('click', () => submit(widget.dataset.startUrl));
    recoverButton.addEventListener('click', () => submit(widget.dataset.recoverUrl));
    cancelButton.addEventListener('click', cancel);
    resumeButton?.addEventListener('click', () => submit(resumeButton.dataset.masterResumeUrl));
    if (progressUrl) poll();
});
