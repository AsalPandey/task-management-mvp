@extends('layouts.app')
@push('styles')
<link rel="stylesheet" href="{{ asset('css/dashboard.css') }}">
<link rel="stylesheet" href="{{ asset('css/login.css') }}">
<link rel="stylesheet" href="{{ asset('css/common.css') }}">
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
            <h3>Demo Credentials:</h3>
            <div class="credentials">
                <div class="credential-item">
                    <div class="credential-header">
                        <strong>Manager Account</strong>
                        <span class="credential-badge manager">Manager</span>
                    </div>
                    <div class="credential-details">
                        <span>asalpandey44@gmail.com</span>
                        <span>PLMokn!@#123</span>
                    </div>
                </div>
                <div class="credential-item">
                    <div class="credential-header">
                        <strong>Team Member - Aniket</strong>
                        <span class="credential-badge team">Team</span>
                    </div>
                    <div class="credential-details">
                        <span>aniket@techcorp.com</span>
                        <span>aniket123</span>
                    </div>
                </div>
                <div class="credential-item">
                    <div class="credential-header">
                        <strong>Team Member - Achyut</strong>
                        <span class="credential-badge team">Team</span>
                    </div>
                    <div class="credential-details">
                        <span>achyut@techcorp.com</span>
                        <span>achyut123</span>
                    </div>
                </div>
                <div class="credential-item">
                    <div class="credential-header">
                        <strong>Team Member - Bishal</strong>
                        <span class="credential-badge team">Team</span>
                    </div>
                    <div class="credential-details">
                        <span>bishal@techcorp.com</span>
                        <span>bishal123</span>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
@endSection 