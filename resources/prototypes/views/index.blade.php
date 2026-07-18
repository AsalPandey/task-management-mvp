@extends('layouts.app')
@push('styles')
<link rel="stylesheet" href="{{ asset('css/dashboard.css') }}">
<link rel="stylesheet" href="{{ asset('css/login.css') }}">
<link rel="stylesheet" href="{{ asset('css/common.css') }}">
<style>
body { background: #f7f8fa; }
.login-container { max-width: 400px; margin: 3rem auto; padding: 2rem 1.5rem; background: #fff; border-radius: 16px; box-shadow: 0 2px 16px 0 rgba(60,72,88,0.05); }
.login-card { background: #f8fafc; border-radius: 12px; box-shadow: none; padding: 2rem 1.5rem; }
.login-header h1 { font-size: 1.5rem; font-weight: 700; color: #22223b; margin-bottom: 0.2rem; }
.login-header p { color: #888; font-size: 1.05rem; }
.form-group { margin-bottom: 1.2rem; }
.input-group { display: flex; align-items: center; background: #fff; border-radius: 8px; border: 1px solid #e5e7eb; padding: 0.3rem 0.7rem; }
.input-icon { margin-right: 0.5rem; color: #4f8cff; }
input[type="email"], input[type="password"] { border: none; outline: none; background: none; font-size: 1rem; flex: 1; padding: 0.5rem 0; }
.error-message { background: #ffeaea; color: #d33; border-radius: 8px; padding: 0.7rem 1rem; margin-bottom: 1rem; }
button[type="submit"] { background: #4f8cff; color: #fff; border: none; border-radius: 8px; padding: 0.7rem 1.2rem; font-size: 1rem; cursor: pointer; width: 100%; margin-top: 0.5rem; }
@media (max-width: 600px) { .login-container { padding: 1rem 0.2rem; } .login-card { padding: 1rem 0.5rem; } }
</style>
@endpush
@section('content')
<div class="login-container">
    <div class="login-card">
        <div class="login-header">
            <div class="logo">
                <div class="logo-icon">
                    <svg width="32" height="32" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <path d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                </div>
                <h1>TaskFlow</h1>
            </div>
            <p>Sign in to manage your team's tasks</p>
        </div>
        <form method="POST" action="{{ route('login') }}" class="login-form">
            @csrf
            <div class="form-group">
                <label for="email">Login ID</label>
                <div class="input-group">
                    <span class="input-icon">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                            <path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z" stroke="currentColor" stroke-width="2"/>
                            <polyline points="22,6 12,13 2,6" stroke="currentColor" stroke-width="2"/>
                        </svg>
                    </span>
                    <input type="email" id="email" name="email" placeholder="Enter your login ID" required autofocus>
                </div>
            </div>
            <div class="form-group">
                <label for="password">Password</label>
                <div class="input-group">
                    <span class="input-icon">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                            <rect x="3" y="11" width="18" height="11" rx="2" ry="2" stroke="currentColor" stroke-width="2"/>
                            <circle cx="12" cy="16" r="1" fill="currentColor"/>
                            <path d="M7 11V7a5 5 0 0 1 10 0v4" stroke="currentColor" stroke-width="2"/>
                        </svg>
                    </span>
                    <input type="password" id="password" name="password" placeholder="Enter your password" required>
                </div>
            </div>
            @if ($errors->any())
                <div class="error-message">
                    @foreach ($errors->all() as $error)
                        <div>{{ $error }}</div>
                    @endforeach
                </div>
            @endif
            <button type="submit" class="login-btn">
                <span class="btn-text">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4" stroke="currentColor" stroke-width="2"/>
                        <polyline points="10,17 15,12 10,7" stroke="currentColor" stroke-width="2"/>
                        <line x1="15" y1="12" x2="3" y2="12" stroke="currentColor" stroke-width="2"/>
                    </svg>
                    Sign In
                </span>
            </button>
        </form>
        <div class="demo-credentials">
            <h3>Archived Prototype</h3>
            <p>No accounts or credentials are included. This interface is historical reference material only.</p>
        </div>
    </div>
</div>
@endSection
