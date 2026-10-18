@extends('layouts.app')
@section('title', 'Autentificare')
@section('content')
<section class="card login">
    <p class="eyebrow">Cont utilizator</p>
    <h1>Autentificare</h1>
    <p class="muted">Intră în cont pentru a vedea biletele tale.</p>
    <form method="post" action="{{ route('user.authenticate') }}">
        @csrf
        <label class="muted form-label" for="email">Email</label>
        <input id="email" class="field login-username-field" type="email" name="email" value="{{ old('email') }}" autocomplete="email" required autofocus>
        <label class="muted form-label login-password-label" for="password">Parolă</label>
        <input id="password" class="field login-password-field" type="password" name="password" autocomplete="current-password" required>
        <button class="btn btn-block" type="submit">Intră în cont</button>
    </form>
    <p class="muted login-hint">Nu ai cont? <a href="{{ route('user.register') }}">Creează cont</a></p>
</section>
@endsection
