@extends('layouts.app')
@section('title', 'Cont nou')
@section('content')
<section class="card login">
    <p class="eyebrow">Cont utilizator</p>
    <h1>Creează cont</h1>
    <p class="muted">Biletele tale vor fi vizibile doar în contul tău.</p>
    <form method="post" action="{{ route('user.register.store') }}">
        @csrf
        <label class="muted form-label" for="name">Nume</label>
        <input id="name" class="field login-username-field" name="name" value="{{ old('name') }}" autocomplete="name" required maxlength="100">
        <label class="muted form-label" for="email">Email</label>
        <input id="email" class="field login-username-field" type="email" name="email" value="{{ old('email') }}" autocomplete="email" required>
        <label class="muted form-label" for="password">Parolă (minimum 12 caractere)</label>
        <input id="password" class="field login-username-field" type="password" name="password" autocomplete="new-password" required minlength="12">
        <label class="muted form-label" for="password_confirmation">Confirmă parola</label>
        <input id="password_confirmation" class="field login-password-field" type="password" name="password_confirmation" autocomplete="new-password" required minlength="12">
        <button class="btn btn-block" type="submit">Creează cont</button>
    </form>
    <p class="muted login-hint">Ai deja cont? <a href="{{ route('user.login') }}">Autentifică-te</a></p>
</section>
@endsection
