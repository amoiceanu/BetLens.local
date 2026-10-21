const page = document.querySelector('[data-team-aliases]');

if (page) {
    const teamSearch = page.querySelector('[data-team-search]');
    const teamId = page.querySelector('[data-team-id]');
    const options = page.querySelector('[data-team-options]');
    const selected = page.querySelector('[data-selected-team]');
    const aliasInput = page.querySelector('[data-alias-input]');
    const normalizedInput = page.querySelector('[data-normalized-input]');
    const editModal = page.querySelector('[data-edit-modal]');
    const editForm = page.querySelector('[data-edit-form]');
    const editTeamId = page.querySelector('[data-edit-team-id]');
    const editTeamSearch = page.querySelector('[data-edit-team-search]');
    const editTeamOptions = page.querySelector('[data-edit-team-options]');
    const editSelected = page.querySelector('[data-edit-selected]');
    let normalizeTimer;

    const normalize = async (value, target) => {
        window.clearTimeout(normalizeTimer);
        if (!value.trim()) { target.value = ''; return; }
        normalizeTimer = window.setTimeout(async () => {
            try {
                const url = new URL(page.dataset.normalizeUrl, window.location.origin);
                url.searchParams.set('q', value);
                const response = await fetch(url, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
                if (response.ok) target.value = (await response.json()).normalized;
            } catch { target.value = ''; }
        }, 180);
    };

    aliasInput?.addEventListener('input', () => normalize(aliasInput.value, normalizedInput));
    const editAliasInput = page.querySelector('[data-edit-alias-input]');
    editAliasInput?.addEventListener('input', () => normalize(editAliasInput.value, page.querySelector('[data-edit-normalized]')));

    const searchTeams = (input, hiddenId, optionList, selection, timerName) => {
        input?.addEventListener('input', () => {
            hiddenId.value = '';
            selection.textContent = 'Selectează o echipă din rezultate.';
            window.clearTimeout(timerName.value);
            const term = input.value.trim();
            if (term.length < 2) { optionList.hidden = true; optionList.replaceChildren(); return; }
            timerName.value = window.setTimeout(async () => {
                try {
                    const url = new URL(page.dataset.teamsUrl, window.location.origin);
                    url.searchParams.set('q', term);
                    const response = await fetch(url, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
                    if (!response.ok) throw new Error('Căutarea echipelor nu a reușit.');
                    const teams = await response.json();
                    optionList.replaceChildren();
                    if (!teams.length) {
                        const empty = document.createElement('p'); empty.textContent = 'Nu am găsit echipe.'; optionList.append(empty);
                    } else teams.forEach((team) => {
                        const button = document.createElement('button'); button.type = 'button'; button.className = 'alias-team-option';
                        if (team.crest) { const img = document.createElement('img'); img.src = team.crest; img.alt = ''; button.append(img); }
                        const text = document.createElement('span'); const name = document.createElement('strong'); name.textContent = team.name;
                        const context = document.createElement('small'); context.textContent = team.league ?? 'Echipă canonică';
                        text.append(name, context); button.append(text);
                        button.addEventListener('click', () => {
                            hiddenId.value = team.id; input.value = team.name;
                            selection.textContent = `${team.name}${team.league ? ` · ${team.league}` : ''}`;
                            optionList.hidden = true;
                        });
                        optionList.append(button);
                    });
                    optionList.hidden = false;
                } catch {
                    optionList.replaceChildren(); const error = document.createElement('p'); error.textContent = 'Eroare la căutarea echipelor.'; optionList.append(error); optionList.hidden = false;
                }
            }, 250);
        });
    };
    const addTimer = { value: null }, editTimer = { value: null };
    searchTeams(teamSearch, teamId, options, selected, addTimer);
    searchTeams(editTeamSearch, editTeamId, editTeamOptions, editSelected, editTimer);
    document.addEventListener('click', (event) => {
        if (!event.target.closest('.alias-team-picker')) { options.hidden = true; editTeamOptions.hidden = true; }
    });

    page.querySelectorAll('[data-edit-alias]').forEach((button) => button.addEventListener('click', () => {
        editForm.action = button.dataset.updateUrl;
        page.querySelector('[data-edit-team-id]').value = button.dataset.teamId;
        editTeamSearch.value = button.dataset.teamName;
        editSelected.textContent = button.dataset.teamName;
        editAliasInput.value = button.dataset.alias;
        page.querySelector('[data-edit-normalized]').value = button.dataset.normalized;
        editModal.hidden = false;
        page.querySelector('[data-close-modal]').focus();
    }));
    const closeModal = () => { editModal.hidden = true; };
    page.querySelector('[data-close-modal]')?.addEventListener('click', closeModal);
    editModal?.addEventListener('click', (event) => { if (event.target === editModal) closeModal(); });
    document.addEventListener('keydown', (event) => { if (event.key === 'Escape' && editModal && !editModal.hidden) closeModal(); });

    const selectAll = page.querySelector('[data-select-all]');
    const checks = [...page.querySelectorAll('[data-alias-check]')];
    const deleteButton = page.querySelector('[data-delete-selected]');
    const updateSelection = () => {
        const count = checks.filter((check) => check.checked).length;
        deleteButton.disabled = count === 0;
        selectAll.checked = checks.length > 0 && count === checks.length;
        selectAll.indeterminate = count > 0 && count < checks.length;
    };
    selectAll?.addEventListener('change', () => { checks.forEach((check) => { check.checked = selectAll.checked; }); updateSelection(); });
    checks.forEach((check) => check.addEventListener('change', updateSelection));
    page.querySelector('[data-bulk-delete]')?.addEventListener('submit', (event) => {
        const count = checks.filter((check) => check.checked).length;
        if (!count || !window.confirm(`Ștergi cele ${count} aliasuri selectate? Echipele și datele importate rămân intacte.`)) event.preventDefault();
    });
}
