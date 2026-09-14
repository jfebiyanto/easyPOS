@extends('layouts.guest')

@section('content')
<div class="login-page">
    <div class="login-card">
        <div class="login-brand">
            <span class="material-symbols-rounded">point_of_sale</span>
            <h1>easyPOS</h1>
        </div>
        <p class="login-subtitle">Masuk ke Administrator Backoffice</p>

        @if ($errors->any())
            <div class="login-error">{{ $errors->first() }}</div>
        @endif

        <form method="POST" action="{{ route('login.attempt') }}">
            @csrf
            <div class="field">
                <label for="username">Username</label>
                <input type="text" id="username" name="username" value="{{ old('username') }}" required autofocus autocomplete="username">
            </div>
            <div class="field">
                <label for="password">Password</label>
                <input type="password" id="password" name="password" required autocomplete="current-password">
            </div>
            <div class="field">
                <div class="checkbox-row">
                    <input type="checkbox" id="remember" name="remember" value="1">
                    <label for="remember">Ingat saya</label>
                </div>
            </div>
            <button type="submit" class="btn btn-filled" style="width: 100%; justify-content: center;">
                Masuk
            </button>
        </form>
    </div>
</div>
@endsection