<aside class="admin-sidebar" aria-label="Meniu administrator">
    <nav aria-label="Navigație administrator">
        <span class="admin-sidebar-heading">Administrare</span>
        <a href="{{ route('operators.index') }}" class="{{ request()->routeIs('operators.*') ? 'active' : '' }}" @if(request()->routeIs('operators.*')) aria-current="page" @endif><i data-lucide="database" size="17" aria-hidden="true"></i>Operatori</a>
        <a href="{{ route('data-sources') }}" class="{{ request()->routeIs('data-sources*') ? 'active' : '' }}" @if(request()->routeIs('data-sources*')) aria-current="page" @endif><i data-lucide="link" size="17" aria-hidden="true"></i>Surse de date</a>
        <a href="{{ route('admin.coverage') }}" class="{{ request()->routeIs('admin.coverage*') ? 'active' : '' }}" @if(request()->routeIs('admin.coverage*')) aria-current="page" @endif><i data-lucide="grid-3x3" size="17" aria-hidden="true"></i>Acoperirea surselor</a>
        <a href="{{ route('admin.mindmap') }}" class="{{ request()->routeIs('admin.mindmap*') ? 'active' : '' }}" @if(request()->routeIs('admin.mindmap*')) aria-current="page" @endif><i data-lucide="workflow" size="17" aria-hidden="true"></i>Data Mindmap</a>
        <a href="{{ route('admin.simulator') }}" class="{{ request()->routeIs('admin.simulator') ? 'active' : '' }}" @if(request()->routeIs('admin.simulator')) aria-current="page" @endif><i data-lucide="sliders-horizontal" size="17" aria-hidden="true"></i>Simulator</a>
        <a href="{{ route('admin.import-audit') }}" class="{{ request()->routeIs('admin.import-audit') ? 'active' : '' }}" @if(request()->routeIs('admin.import-audit')) aria-current="page" @endif><i data-lucide="clipboard-list" size="17" aria-hidden="true"></i>Audit importuri</a>
        <a href="{{ route('admin.aliases.index') }}" class="{{ request()->routeIs('admin.aliases.*') ? 'active' : '' }}" @if(request()->routeIs('admin.aliases.*')) aria-current="page" @endif><i data-lucide="list-filter" size="17" aria-hidden="true"></i>Aliasuri echipe</a>
    </nav>
</aside>
