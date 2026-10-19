const center = document.getElementById('import-center');

if (center) {
    const data = JSON.parse(document.getElementById('ic-data').textContent);
    const byId = id => document.getElementById(id);
    const token = center.querySelector('input[name="_token"]').value;
    const sourceUrl = id => `${data.urls.sourceBase}/${id}`;
    const runUrl = id => `${data.urls.runBase}/${id}/progress`;
    const ongoing = new Set();
    const submitting = new Set();
    let selected = null;

    const mapPanel = byId('ic-map-panel');
    const fullscreenButton = byId('ic-fullscreen');
    const fullscreenActive = () => document.fullscreenElement === mapPanel || mapPanel.classList.contains('ic-maximized');
    const syncFullscreenButton = () => {
        const active = fullscreenActive();
        fullscreenButton.setAttribute('aria-pressed', String(active));
        fullscreenButton.setAttribute('aria-label', active ? 'Ieși din ecran complet' : 'Afișează harta pe ecran complet');
        fullscreenButton.title = active ? 'Ieși din ecran complet' : 'Ecran complet';
        fullscreenButton.querySelector('span').textContent = active ? 'Ieși din ecran complet' : 'Ecran complet';
    };
    fullscreenButton.addEventListener('click', async () => {
        if (document.fullscreenElement === mapPanel) {
            await document.exitFullscreen();
        } else if (mapPanel.classList.contains('ic-maximized')) {
            mapPanel.classList.remove('ic-maximized');
            document.body.classList.remove('ic-fullscreen-fallback');
        } else {
            try {
                if (!mapPanel.requestFullscreen) throw new Error('Fullscreen API indisponibil');
                await mapPanel.requestFullscreen();
            } catch {
                mapPanel.classList.add('ic-maximized');
                document.body.classList.add('ic-fullscreen-fallback');
            }
        }
        syncFullscreenButton();
    });
    document.addEventListener('fullscreenchange', syncFullscreenButton);
    document.addEventListener('keydown', event => {
        if (event.key === 'Escape' && mapPanel.classList.contains('ic-maximized')) {
            mapPanel.classList.remove('ic-maximized');
            document.body.classList.remove('ic-fullscreen-fallback');
            syncFullscreenButton();
        }
    });

    const feedback = message => { byId('ic-feedback').textContent = message; };
    const request = async (url, options = {}) => {
        const response = await fetch(url, { credentials: 'same-origin', headers: { Accept: 'application/json', 'X-CSRF-TOKEN': token, ...(options.headers || {}) }, ...options });
        const result = await response.json();
        if (!response.ok) throw new Error(result.message || Object.values(result.errors || {})[0]?.[0] || 'Cererea nu a reușit.');
        return result;
    };
    const runLabel = status => ({ queued: 'În coadă', running: 'În curs', completed: 'Reușit', partial: 'Parțial', failed: 'Eșuat' })[status] || status;
    const formatTime = value => value ? new Date(value).toLocaleString('ro-RO') : 'Niciodată';
    const syncImportButtons = sourceId => {
        const row = data.rows.find(item => Number(item.id) === Number(sourceId));
        if (!row) return;
        const busy = !!row.activeRunId || submitting.has(Number(sourceId));
        const label = submitting.has(Number(sourceId)) ? 'Se pornește' : row.activeRunStatus === 'running' ? 'Se importă' : 'În coadă';
        const buttons = [...center.querySelectorAll(`[data-import-source="${Number(sourceId)}"]`)];
        if (Number(selected?.id) === Number(sourceId)) buttons.push(byId('ic-import'));
        buttons.forEach(button => {
            button.disabled = busy || !row.canImport;
            button.classList.toggle('is-loading', busy);
            button.setAttribute('aria-busy', String(busy));
            button.textContent = busy ? label : button.dataset.idleLabel;
            button.title = busy ? `${label}${row.activeRunId ? ` · import #${row.activeRunId}` : ''}` : '';
        });
    };

    const showTab = tab => {
        center.querySelectorAll('[data-tab]').forEach(button => button.setAttribute('aria-selected', String(button.dataset.tab === tab)));
        center.querySelectorAll('[data-panel]').forEach(panel => { panel.hidden = panel.dataset.panel !== tab; });
    };
    center.querySelectorAll('[data-tab]').forEach(button => button.addEventListener('click', () => showTab(button.dataset.tab)));

    const selectSource = id => {
        selected = data.rows.find(row => Number(row.id) === Number(id));
        if (!selected) return;
        center.querySelectorAll('.ic-source').forEach(node => node.classList.toggle('selected', Number(node.dataset.sourceId) === Number(id)));
        byId('ic-detail-icon').textContent = selected.name.charAt(0);
        byId('ic-detail-name').textContent = selected.name;
        byId('ic-detail-level').textContent = selected.levels.map(level => `L${level} · ${data.levels[level].name}`).join(' · ');
        const badge = byId('ic-detail-status');
        badge.textContent = selected.statusLabel;
        badge.className = `ic-status ic-status-${selected.status}`;
        byId('ic-detail-website').textContent = selected.website || 'Indisponibil';
        byId('ic-detail-endpoint').textContent = selected.endpoint || 'Niciun endpoint aprobat';
        byId('ic-detail-method').textContent = ({ existing: 'Adaptor existent', manual_json: 'JSON manual', manual_csv: 'CSV manual', internal_model: 'Recalculare internă', unsupported: 'Nesuportat' })[selected.method] || selected.method;
        byId('ic-detail-coverage').textContent = selected.competition;
        byId('ic-detail-sync').textContent = formatTime(selected.lastSync);
        byId('ic-detail-next').textContent = selected.nextRun ? formatTime(selected.nextRun) : 'Doar manual';
        byId('ic-detail-count').textContent = Number(selected.recordsCount || 0).toLocaleString('ro-RO');
        byId('ic-config-form').action = sourceUrl(id);
        byId('ic-config-level').value = selected.level;
        byId('ic-config-frequency').value = selected.frequency || 'manual';
        byId('ic-config-timeout').value = selected.timeout || 20;
        byId('ic-config-active').checked = !!selected.active;
        byId('ic-config-enabled').checked = !!selected.importEnabled;
        if (byId('ic-coverage-form')) {
            byId('ic-coverage-form').action = `${sourceUrl(id)}/competition`;
            byId('ic-coverage-status').value = ['supported', 'unsupported'].includes(selected.competition) ? selected.competition : 'unverified';
            byId('ic-coverage-id').value = selected.externalCompetitionId || '';
        }
        byId('ic-upload-form').action = `${sourceUrl(id)}/upload`;
        byId('ic-upload-form').hidden = !['manual_json', 'manual_csv'].includes(selected.method);
        byId('ic-mapping-form').action = `${sourceUrl(id)}/mapping`;
        byId('ic-mapping-form').hidden = !['manual_json', 'manual_csv'].includes(selected.method);
        byId('ic-import').hidden = ['manual_json', 'manual_csv'].includes(selected.method);
        syncImportButtons(id);
        byId('ic-test').textContent = selected.slug === 'api-football' ? 'Verifică acum' : 'Testează conexiunea';
        byId('ic-test').disabled = selected.slug === 'api-football' ? !selected.canImport : (!selected.endpoint || selected.method !== 'existing');
        byId('ic-mapping-list').replaceChildren();
        if (selected.mappings.length) selected.mappings.forEach(mapping => {
            const item = document.createElement('li'); item.textContent = `${mapping.external_field} → ${mapping.internal_field}`; byId('ic-mapping-list').append(item);
        });
        else { const item = document.createElement('li'); item.textContent = 'Nicio mapare configurată.'; byId('ic-mapping-list').append(item); }
        byId('ic-source-history').replaceChildren();
        const runs = data.runs.filter(run => Number(run.source_id) === Number(id));
        if (runs.length) runs.forEach(run => {
            const item = document.createElement('p');
            item.textContent = `#${run.id} · ${runLabel(run.status)} · ${run.duration_ms == null ? 'durată —' : `${run.duration_ms} ms`} · ${run.records_fetched ?? 0} primite · +${run.records_new ?? 0} noi / ${run.records_updated ?? 0} actualizate / ${run.records_unchanged ?? 0} neschimbate / ${run.records_errors ?? 0} eșuate${run.message ? ' · ' + run.message : ''}`;
            byId('ic-source-history').append(item);
        });
        else byId('ic-source-history').textContent = 'Nicio rulare recentă pentru această sursă.';
        const latestFunctionRun = runs.find(run => run.metadata && Number.isInteger(run.metadata.identified));
        byId('ic-function-summary').hidden = selected.slug !== 'api-football';
        for (const [field, value] of [['ic-competitions-received', latestFunctionRun?.metadata?.identified], ['ic-competitions-saved', latestFunctionRun?.metadata?.competitions_saved], ['ic-seasons-saved', latestFunctionRun?.metadata?.seasons_saved]]) {
            byId(field).textContent = value == null ? '—' : Number(value).toLocaleString('ro-RO');
        }
        byId('ic-preview-output').hidden = true;
        feedback(selected.canImport ? 'Sursa este pregătită pentru acțiunile disponibile.' : 'Importul este indisponibil pentru configurația sau liga selectată.');
        byId('ic-details').classList.remove('collapsed');
        byId('ic-details-toggle').setAttribute('aria-expanded', 'true');
    };
    center.querySelectorAll('[data-select-source]').forEach(button => button.addEventListener('click', () => { selectSource(button.dataset.selectSource); showTab('general'); }));
    byId('ic-details-toggle').addEventListener('click', () => {
        const collapsed = byId('ic-details').classList.toggle('collapsed');
        byId('ic-details-toggle').setAttribute('aria-expanded', String(!collapsed));
    });

    const poll = async id => {
        if (ongoing.has(id)) return;
        ongoing.add(id);
        for (let attempt = 0; ongoing.has(id); attempt++) {
            await new Promise(resolve => setTimeout(resolve, attempt < 90 ? 2000 : 10000));
            try {
                const run = await request(runUrl(id));
                const stored = data.runs.find(item => item.id === id);
                if (stored) Object.assign(stored, run);
                const source = data.rows.find(row => Number(row.id) === Number(stored?.source_id));
                if (source) { source.activeRunStatus = run.status; syncImportButtons(source.id); }
                feedback(`Import #${id}: ${runLabel(run.status)} · +${run.records_new} noi, ${run.records_updated} actualizate, ${run.records_errors} erori.${run.message ? ' ' + run.message : ''}`);
                if (['completed', 'partial', 'failed'].includes(run.status)) {
                    if (source) {
                        source.activeRunId = null;
                        source.activeRunStatus = null;
                        source.status = run.status === 'completed' ? 'healthy' : (run.status === 'failed' ? 'unavailable' : 'degraded');
                        source.statusLabel = run.status === 'completed' ? 'Disponibilă' : (run.status === 'failed' ? 'Indisponibilă' : 'Parțial');
                        source.recordsCount += run.records_new;
                        if (run.status === 'completed') source.lastSync = new Date().toISOString();
                        center.querySelectorAll(`.ic-source[data-source-id="${CSS.escape(String(source.id))}"]`).forEach(node => {
                            node.dataset.sourceStatus = source.status;
                            const badge = node.querySelector('.ic-status'); badge.textContent = source.statusLabel; badge.className = `ic-status ic-status-${source.status}`;
                            if (run.status === 'completed') node.querySelector('time').textContent = new Date().toLocaleString('ro-RO', { day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' });
                        });
                        if (selected?.id === source.id) selectSource(source.id);
                        syncImportButtons(source.id);
                    }
                    ongoing.delete(id); return;
                }
            } catch (error) { feedback(`${error.message} Import #${id} rămâne în așteptare; reîncercăm actualizarea.`); }
        }
    };
    const addRun = (id, sourceId) => {
        const row = data.rows.find(item => Number(item.id) === Number(sourceId));
        if (!row) return;
        row.activeRunId = id;
        row.activeRunStatus = 'queued';
        data.runs.unshift({ id, source_id: sourceId, status: 'queued', records_new: 0, records_updated: 0, records_errors: 0 });
        syncImportButtons(sourceId);
        poll(id);
    };
    const postImport = async sourceId => {
        const row = data.rows.find(item => Number(item.id) === Number(sourceId));
        if (!row?.canImport || row.activeRunId || submitting.has(Number(sourceId))) return;
        submitting.add(Number(sourceId));
        syncImportButtons(sourceId);
        feedback(`Se adaugă ${row.name} în coadă…`);
        try {
            const form = new FormData(); if (data.leagueId) form.append('league', data.leagueId); if (data.seasonId) form.append('season', data.seasonId);
            const result = await request(`${sourceUrl(sourceId)}/import`, { method: 'POST', body: form });
            addRun(result.run_id, sourceId); feedback(`Import #${result.run_id} adăugat în coadă.`);
        } catch (error) { feedback(error.message); }
        finally { submitting.delete(Number(sourceId)); syncImportButtons(sourceId); }
    };
    center.querySelectorAll('[data-import-source]').forEach(button => button.addEventListener('click', () => postImport(button.dataset.importSource)));
    byId('ic-import').addEventListener('click', () => selected && postImport(selected.id));

    const postMany = async (url, confirmAll = false) => {
        if (confirmAll && !window.confirm('Pornești toate sursele configurate și importabile? Pot fi consumate cote API.')) return;
        const form = new FormData(); if (data.leagueId) form.append('league', data.leagueId); if (data.seasonId) form.append('season', data.seasonId); if (confirmAll) form.append('confirm', '1');
        try {
            const result = await request(url, { method: 'POST', body: form });
            feedback(result.message);
            (result.runs || []).forEach(run => addRun(run.id, run.source_id));
        } catch (error) { feedback(error.message); }
    };
    center.querySelectorAll('[data-import-level]').forEach(button => button.addEventListener('click', () => postMany(`${data.urls.levelBase}/${button.dataset.importLevel}/import`)));
    byId('ic-import-all').addEventListener('click', () => postMany(data.urls.all, true));
    byId('ic-test').addEventListener('click', async () => {
        if (!selected) return;
        feedback('Se testează conexiunea fără import…');
        try { const result = await request(`${sourceUrl(selected.id)}/test`, { method: 'POST' }); feedback(result.message); } catch (error) { feedback(error.message); }
    });
    byId('ic-preview').addEventListener('click', async () => {
        if (!selected) return;
        try { const result = await request(`${sourceUrl(selected.id)}/preview`); byId('ic-preview-output').textContent = result.records.length ? JSON.stringify(result.records, null, 2) : 'Nu există încă date importate pentru această sursă.'; byId('ic-preview-output').hidden = false; } catch (error) { feedback(error.message); }
    });

    const filter = () => {
        const query = byId('ic-search').value.toLocaleLowerCase('ro-RO');
        const level = byId('ic-level-filter').value;
        const status = byId('ic-status-filter').value;
        center.querySelectorAll('.ic-level').forEach(card => {
            card.hidden = !!level && card.dataset.level !== level;
            let visible = 0;
            card.querySelectorAll('.ic-source').forEach(node => {
                node.hidden = (!!query && !node.dataset.sourceName.includes(query)) || (!!status && node.dataset.sourceStatus !== status);
                if (!node.hidden) visible++;
            });
            card.classList.toggle('ic-filter-empty', !visible);
        });
    };
    ['ic-search', 'ic-level-filter', 'ic-status-filter'].forEach(id => byId(id).addEventListener('input', filter));
    center.querySelectorAll('.ic-collapse').forEach(button => button.addEventListener('click', () => {
        const card = button.closest('.ic-level'); const collapsed = card.classList.toggle('collapsed');
        button.setAttribute('aria-expanded', String(!collapsed));
    }));
    byId('ic-league').addEventListener('change', () => { byId('ic-season').value = ''; });
    byId('ic-add-source').addEventListener('click', () => byId('ic-add-dialog').showModal());
    data.rows.filter(row => row.activeRunId).forEach(row => {
        if (!data.runs.some(run => Number(run.id) === Number(row.activeRunId))) {
            data.runs.unshift({ id: row.activeRunId, source_id: row.id, status: row.activeRunStatus, records_new: 0, records_updated: 0, records_errors: 0 });
        }
        syncImportButtons(row.id);
        poll(row.activeRunId);
    });
    if (data.rows.length) selectSource(data.rows[0].id);
}
