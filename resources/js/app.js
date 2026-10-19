import './bootstrap';
import './mindmap';
import './import-center';
import './source-progress';
import './source-tabs';
import './upcoming-import';

for (const form of document.querySelectorAll('[data-coverage-filter]')) {
    form.addEventListener('submit', () => { const status = form.querySelector('[data-loading]'); if (status) status.hidden = false; });
    form.querySelector('[name=league]')?.addEventListener('change', () => { const season = form.querySelector('[name=season]'); if (season) season.value = ''; });
}
