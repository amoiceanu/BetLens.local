const root = document.getElementById('mindmap-app');
if (root) {
    const data = JSON.parse(document.getElementById('mm-data').textContent);
    const $ = id => document.getElementById(id);
    const viewport = $('mm-viewport'), world = $('mm-world');
    let scale = 1, x = 0, y = 0, drag, selected, page = 1, direction = 1, sort = 'name';
    const announce = message => { $('mm-live').textContent = message; };
    const transform = () => { world.style.transform = `translate(${x}px, ${y}px) scale(${scale})`; $('mm-zoom').textContent = `${Math.round(scale * 100)}%`; };
    const fit = (readable = false) => { scale = Math.min(viewport.clientWidth / 1080, viewport.clientHeight / 790); if (readable && viewport.clientWidth < 760) scale = Math.max(.7, scale); x = (viewport.clientWidth - 1080 * scale) / 2; y = (viewport.clientHeight - 790 * scale) / 2; transform(); };
    const zoom = factor => { const next = Math.max(.25, Math.min(2.5, scale * factor)); const cx = viewport.clientWidth / 2, cy = viewport.clientHeight / 2; x = cx - (cx - x) * next / scale; y = cy - (cy - y) * next / scale; scale = next; transform(); };
    $('mm-zoom-in').onclick = () => zoom(1.2);
    $('mm-zoom-out').onclick = () => zoom(1 / 1.2);
    $('mm-fit').onclick = () => fit();
    viewport.addEventListener('wheel', event => { if (event.ctrlKey) { event.preventDefault(); zoom(event.deltaY < 0 ? 1.1 : 1 / 1.1); } }, { passive: false });
    viewport.addEventListener('pointerdown', event => { if (event.target.closest('button') || event.button !== 0) return; drag = { px: event.clientX, py: event.clientY, x, y }; viewport.setPointerCapture(event.pointerId); viewport.classList.add('dragging'); });
    viewport.addEventListener('pointermove', event => { if (!drag) return; x = drag.x + event.clientX - drag.px; y = drag.y + event.clientY - drag.py; transform(); });
    const stopDrag = () => { drag = null; viewport.classList.remove('dragging'); };
    viewport.addEventListener('pointerup', stopDrag); viewport.addEventListener('pointercancel', stopDrag);
    viewport.addEventListener('keydown', event => {
        if (event.target !== viewport) return;
        if (['+', '-', '0', 'ArrowLeft', 'ArrowRight', 'ArrowUp', 'ArrowDown'].includes(event.key)) event.preventDefault();
        if (event.key === '+') zoom(1.2); if (event.key === '-') zoom(1 / 1.2); if (event.key === '0') fit();
        if (event.key === 'ArrowLeft') x += 40; if (event.key === 'ArrowRight') x -= 40; if (event.key === 'ArrowUp') y += 40; if (event.key === 'ArrowDown') y -= 40; transform();
    });
    new ResizeObserver(() => fit(true)).observe(viewport);
    root.querySelectorAll('[data-collapse]').forEach(button => { button.onclick = () => { const expanded = button.getAttribute('aria-expanded') === 'true'; button.setAttribute('aria-expanded', String(!expanded)); button.nextElementSibling.hidden = expanded; button.querySelector('.mm-chevron').textContent = expanded ? '+' : '−'; }; });
    const filters = () => {
        const query = $('mm-search').value.toLocaleLowerCase('ro'), category = $('mm-category').value, status = $('mm-status').value;
        return data.rows.filter(row => (!query || `${row.name} ${row.category} ${row.typeLabel}`.toLocaleLowerCase('ro').includes(query)) && (!category || row.group === category) && (!status || row.status === status));
    };
    const render = () => {
        const rows = filters().sort((a, b) => String(a[sort] ?? '').localeCompare(String(b[sort] ?? ''), 'ro') * direction);
        page = Math.min(page, Math.max(1, Math.ceil(rows.length / 8)));
        root.querySelectorAll('.mm-node').forEach(node => { node.hidden = !rows.some(row => row.slug === node.dataset.node); });
        root.querySelectorAll('[data-group]').forEach(group => { group.classList.toggle('mm-group-empty', !rows.some(row => row.group === group.dataset.group)); });
        const tbody = $('mm-table-body');
        rows.forEach(row => tbody.appendChild(root.querySelector(`[data-source-row="${CSS.escape(row.slug)}"]`)));
        root.querySelectorAll('[data-source-row]').forEach(tr => { tr.hidden = !rows.slice((page - 1) * 8, page * 8).some(row => row.slug === tr.dataset.sourceRow); });
        $('mm-empty').hidden = !!rows.length;
        $('mm-page').textContent = `Pagina ${page} / ${Math.max(1, Math.ceil(rows.length / 8))}`;
        $('mm-prev').disabled = page === 1; $('mm-next').disabled = page * 8 >= rows.length;
        $('mm-count').textContent = `${rows.length} surse în hartă`;
        $('mm-table-count').textContent = `${rows.filter(row => row.configured).length} înregistrate / ${rows.length} în catalog`;
    };
    root.querySelector('[name=league]').addEventListener('change', () => { root.querySelector('[name=season]').value = ''; });
    ['mm-search', 'mm-category', 'mm-status'].forEach(id => { $(id).addEventListener('input', () => { page = 1; render(); }); });
    $('mm-prev').onclick = () => { page--; render(); }; $('mm-next').onclick = () => { page++; render(); };
    root.querySelectorAll('[data-sort]').forEach(button => { button.onclick = () => { direction = sort === button.dataset.sort ? -direction : 1; sort = button.dataset.sort; render(); }; });
    const detail = (title, entries) => {
        $('mm-detail-title').textContent = title; $('mm-detail-list').replaceChildren();
        entries.forEach(([label, value]) => { const dt = document.createElement('dt'), dd = document.createElement('dd'); dt.textContent = label; dd.textContent = value ?? 'unavailable'; $('mm-detail-list').append(dt, dd); });
        if (!$('mm-drawer').open) $('mm-drawer').showModal();
    };
    const select = row => {
        selected = row;
        root.querySelectorAll('.mm-node').forEach(node => node.classList.toggle('selected', node.dataset.node === row.slug));
        detail(`${row.name}${row.demo ? ' · Demo' : ''}`, [
            ['Sursă și timestamp', `${row.provenance} · ${row.observedAt}`], ['Tip import', row.typeLabel], ['Categorii de date', row.category],
            ['Competiție / sezon', row.competition], ['Endpoint aprobat', row.endpoint], ['Ultima sincronizare', row.lastSync],
            ['Stare', row.statusLabel], ['Înregistrări create / actualizate', `${row.created ?? 'unavailable'} / ${row.updated ?? 'unavailable'}`],
            ['Erori', row.errors], ['Conflicte', row.conflicts === null ? 'unavailable · registru de reconciliere absent' : 'conflict'], ['Detalii', row.errorDetail ?? 'Nicio eroare raportată în starea curentă; lipsa unui jurnal nu certifică disponibilitatea.'],
        ]);
        $('mm-import-form').hidden = !row.canImport;
        $('mm-import-form').action = row.canImport ? `${data.importUrl}/${row.id}/import` : '';
        $('mm-import-note').textContent = row.canImport ? 'Se execută adaptorul existent și se salvează răspunsul real. Acreditările sunt verificate pe server. API-Football importă starea API, nu calendarul meciurilor.' : 'Importator neconfigurat. Este necesar un endpoint aprobat și o implementare reală. Pentru HTML este necesară și permisiunea de acces; CSV/RSS/manual necesită adaptoare dedicate.';
    };
    root.querySelectorAll('[data-node]').forEach(button => { button.onclick = () => select(data.rows.find(row => row.slug === button.dataset.node)); });
    $('mm-import-selected').onclick = () => { if (selected) select(selected); else { announce('Selectează mai întâi o sursă din hartă sau din tabel.'); $('mm-search').focus(); } };
    $('mm-import-form').addEventListener('submit', () => { $('mm-import-button').disabled = true; $('mm-import-button').textContent = 'Import real în curs…'; });
    const stages = {
        core: ['BetLens Sports API', 'Arhitectura datelor', 'Nod conceptual. Aplicația Laravel existentă rămâne sursa de adevăr; nu este creat un serviciu separat.'],
        import: ['Importatori PHP/Laravel', 'REST API · JSON / RSS/Atom / HTML permis / CSV încărcat manual / corectare manuală', 'Doar adaptoarele aprobate existente pot fi executate. Celelalte tipuri sunt metadate de configurare.'],
        validation: ['Validare & deduplicare', 'VerifyDataSource → SourceRecord', 'Cheie externă unică per sursă și checksum SHA-256 pe payload normalizat. Reconcilierea conflictelor între surse nu este încă implementată.'],
        database: ['MySQL BetLens', 'DataSource / SourceRecord / data_sync_logs', 'Persistență existentă: sursa, first_seen_at, last_seen_at și jurnalul de sincronizare.'],
        api: ['Predicții & REST API', 'Servicii de analiză Laravel existente', 'Nod de arhitectură. Pagina nu creează endpointuri REST publice sau predicții simulate. Distribuția REST necesită implementare separată.'],
    };
    root.querySelectorAll('[data-stage]').forEach(button => { button.onclick = () => { const stage = stages[button.dataset.stage]; detail(stage[0], [['Componentă', stage[1]], ['Implementare', stage[2]], ['Proveniență', `Codul proiectului · observat ${data.observedAt}`]]); $('mm-import-form').hidden = true; $('mm-import-note').textContent = ''; }; });
    root.querySelectorAll('[data-add-source]').forEach(button => { button.onclick = () => $('mm-add').showModal(); });
    root.querySelectorAll('[data-close]').forEach(button => { button.onclick = () => button.closest('dialog').close(); });
    root.querySelectorAll('dialog').forEach(dialog => { dialog.addEventListener('click', event => { if (event.target === dialog && (event.clientX < dialog.getBoundingClientRect().left || event.clientX > dialog.getBoundingClientRect().right || event.clientY < dialog.getBoundingClientRect().top || event.clientY > dialog.getBoundingClientRect().bottom)) dialog.close(); }); });
    $('mm-add-type').onchange = () => { const html = $('mm-add-type').value === 'html'; $('mm-html-permission').hidden = !html; $('mm-html-permission').querySelector('input').required = html; };
    const mobile = matchMedia('(max-width: 760px)');
    const collapsePanels = () => { ['mm-health', 'mm-sources'].forEach(id => { $(id).open = !mobile.matches; }); };
    mobile.addEventListener('change', collapsePanels); collapsePanels(); render(); fit(true);
}
