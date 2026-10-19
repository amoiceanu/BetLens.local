<!doctype html>
<html lang="ro">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="color-scheme" content="light">
    <title>@yield('title','BetLens') · analiză fotbal</title>
    <link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    @vite(['resources/scss/app.scss','resources/js/app.js'])
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script><script src="https://cdn.jsdelivr.net/npm/chart.js"></script><script src="https://unpkg.com/lucide@latest"></script>
</head>
<body>
@php($adminAccount=\App\Support\TicketAccess::admin(request()))
@php($userAccount=request()->user())
<header class="header" x-data="{open:false}">
    <nav class="container nav" aria-label="Navigație principală">
        <a class="brand" href="{{ route('dashboard') }}"><span class="brand-mark"><i data-lucide="scan-line" size="19"></i></span>BetLens</a>
        <button class="mobile-toggle" type="button" @click="open=!open" :aria-expanded="open.toString()" aria-controls="main-navigation" aria-label="Deschide meniul"><span aria-hidden="true">☰</span></button>
        <div id="main-navigation" class="navlinks" :class="open&&'open'">
            <a class="{{ request()->routeIs('dashboard')?'active':'' }}" href="{{ route('dashboard') }}"><i data-lucide="house" size="17"></i>Dashboard</a>
            <a class="{{ request()->routeIs('matches*')?'active':'' }}" href="{{ route('matches') }}"><i data-lucide="calendar-days" size="17"></i>Meciuri</a>
            @if($adminAccount || $userAccount)<a class="{{ request()->routeIs('tickets*')?'active':'' }}" href="{{ route('tickets') }}"><i data-lucide="ticket" size="17"></i>Biletele mele</a>@endif
            @if($adminAccount)
            <a class="nav-compact-secondary {{ request()->routeIs('performance')?'active':'' }}" href="{{ route('performance') }}"><i data-lucide="chart-no-axes-column" size="17"></i>Performanță</a>
            <a class="nav-secondary {{ request()->routeIs('operators.*')?'active':'' }}" href="{{ route('operators.index') }}"><i data-lucide="database" size="17"></i>Operatori</a>
            <a class="nav-secondary {{ request()->routeIs('data-sources*')?'active':'' }}" href="{{ route('data-sources') }}"><i data-lucide="link" size="17"></i>Sursa datelor</a>
            <a class="nav-secondary {{ request()->routeIs('admin*')?'active':'' }}" href="{{ route('admin') }}"><i data-lucide="user-round" size="17"></i>Admin</a>
            @endif
            @if($userAccount && !$adminAccount)<form class="nav-logout" method="post" action="{{ route('user.logout') }}">@csrf<button type="submit">Ieși din cont</button></form>@endif
            @if(!$userAccount && !$adminAccount)<a class="{{ request()->routeIs('user.login','user.register')?'active':'' }}" href="{{ route('user.login') }}"><i data-lucide="log-in" size="17"></i>Intră în cont</a>@endif
        </div>
        @if($adminAccount)
        <details class="nav-more {{ request()->routeIs('performance','operators.*','data-sources*','admin*') ? 'active' : '' }}"><summary>Mai multe <i data-lucide="chevron-down" size="15"></i></summary><div class="nav-more-menu">
            <a class="nav-more-performance {{ request()->routeIs('performance')?'active':'' }}" href="{{ route('performance') }}">Performanță</a><a class="{{ request()->routeIs('operators.*')?'active':'' }}" href="{{ route('operators.index') }}">Operatori</a><a class="{{ request()->routeIs('data-sources*')?'active':'' }}" href="{{ route('data-sources') }}">Sursa datelor</a><a class="{{ request()->routeIs('admin*')?'active':'' }}" href="{{ route('admin') }}">Admin</a>
        </div></details>
        @endif
        <span class="nav-spacer"></span>@if($adminAccount)<span class="demo-pill real-data-pill"><span class="demo-dot"></span>Surse reale</span>@endif
    </nav>
</header>
<main class="main"><div class="container">@if(session('success'))<div class="flash">{{ session('success') }}</div>@endif @if(session('warning'))<div class="flash warning">{{ session('warning') }}</div>@endif @if($errors->any())<div class="flash warning">{{ $errors->first() }}</div>@endif @yield('content')</div></main>
<footer class="footer"><div class="container"><span>© {{ date('Y') }} BetLens · Analiză statistică independentă</span><span>BetLens oferă analize statistice, nu garanții. Joacă responsabil.</span>@if($adminAccount)@php($databaseStatus=\App\Support\DatabaseConnectionStatus::current())<span class="admin-database-status {{ $databaseStatus['online'] ? 'is-online' : 'is-other' }}" role="status">BD: {{ $databaseStatus['label'] }}</span>@endif</div></footer>
<script>document.addEventListener('DOMContentLoaded',()=>window.lucide?.createIcons())</script>@stack('scripts')
</body>
</html>
