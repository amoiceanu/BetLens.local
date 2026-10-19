const sourceTabs = [...document.querySelectorAll('[data-source-tab]')];

if (sourceTabs.length) {
    const panels = sourceTabs.map(tab => document.getElementById(tab.getAttribute('aria-controls')));
    const activate = (tab, focus = false) => {
        sourceTabs.forEach((item, index) => {
            const selected = item === tab;
            item.setAttribute('aria-selected', String(selected));
            item.tabIndex = selected ? 0 : -1;
            panels[index].hidden = !selected;
        });
        if (focus) tab.focus();
        try { sessionStorage.setItem('betlens-source-level', tab.dataset.sourceTab); } catch { /* Storage can be unavailable. */ }
    };

    sourceTabs.forEach((tab, index) => {
        tab.addEventListener('click', () => activate(tab));
        tab.addEventListener('keydown', event => {
            let next;
            if (event.key === 'ArrowRight') next = sourceTabs[(index + 1) % sourceTabs.length];
            else if (event.key === 'ArrowLeft') next = sourceTabs[(index - 1 + sourceTabs.length) % sourceTabs.length];
            else if (event.key === 'Home') next = sourceTabs[0];
            else if (event.key === 'End') next = sourceTabs[sourceTabs.length - 1];
            if (next) { event.preventDefault(); activate(next, true); }
        });
    });

    try {
        const saved = sessionStorage.getItem('betlens-source-level');
        const tab = sourceTabs.find(item => item.dataset.sourceTab === saved);
        if (tab) activate(tab);
    } catch { /* The first tab remains active. */ }
}
