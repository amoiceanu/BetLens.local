<aside class="mm-sidebar" aria-label="Navigație admin">
    <a class="brand" href="{{ route('admin') }}"><span class="brand-mark">◎</span> Bet<span>Lens</span></a>
    <small>WORKSPACE / ADMIN</small>
    <nav>
        <a href="{{ route('admin') }}">▦ <span>Overview</span></a>
        <a class="active" aria-current="page" href="{{ route('admin.mindmap') }}">⌘ <span>Mindmap</span></a>
        <a href="{{ route('data-sources') }}">▤ <span>Surse de date</span></a>
        <a href="#mm-sources">⇣ <span>Importuri</span></a>
        <a href="#mm-health">◇ <span>Calitatea datelor</span></a>
        <a href="#mm-estimates">◴ <span>Estimări</span></a>
        <a href="{{ route('admin') }}">⚙ <span>Setări</span></a>
    </nav>
    <div class="mm-sidebar-note"><span>●</span> Date trasabile<br><small>Sursă. Context. Încredere.</small></div>
</aside>
