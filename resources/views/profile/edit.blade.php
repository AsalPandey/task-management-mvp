@extends('layouts.app')

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', () => {
    const modal = document.getElementById('accountDeletionModal');
    const setOpen = open => {
        modal.style.display = open ? 'block' : 'none';
        modal.classList.toggle('active', open);
        document.body.classList.toggle('overflow-y-hidden', open);
    };
    document.getElementById('openAccountDeletion').addEventListener('click', () => setOpen(true));
    modal.querySelector('.modal-close').addEventListener('click', () => setOpen(false));
    modal.addEventListener('click', event => { if (event.target === modal) setOpen(false); });
    // Phase3UI owns focus, Escape, trapping and return-focus for .modal.active.
    if (modal.dataset.show === 'true') requestAnimationFrame(() => setOpen(true));
    document.querySelectorAll('[data-profile-saved]').forEach(message => {
        setTimeout(() => { message.hidden = true; }, 2000);
    });
});
</script>
@endpush

@section('content')

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
            <div>
                <h1 class="text-2xl font-semibold text-gray-900">{{ __('Profile & Security') }}</h1>
                <p class="mt-2 text-sm text-gray-600">Manage your permitted account details and security controls.</p>
            </div>

            <div class="p-4 sm:p-8 bg-white shadow-sm sm:rounded-lg">
                <div class="max-w-xl">
                    @include('profile.partials.update-profile-information-form')
                </div>
            </div>

            <div class="p-4 sm:p-8 bg-white shadow-sm sm:rounded-lg">
                <div class="max-w-xl">
                    <h2 class="text-lg font-medium text-gray-900">{{ __('Notifications & Devices') }}</h2>
                    <p class="mt-1 text-sm text-gray-600">Configure notification preferences and browser push devices on the Settings page.</p>
                    <a class="mt-4 inline-flex items-center rounded-md bg-blue-600 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-700 focus:outline-hidden focus:ring-2 focus:ring-blue-500 focus:ring-offset-2"
                        href="{{ route('settings') }}">{{ __('Open Notification Settings') }}</a>
                </div>
            </div>

            <div class="p-4 sm:p-8 bg-white shadow-sm sm:rounded-lg">
                <div class="max-w-xl">
                    @include('profile.partials.update-password-form')
                </div>
            </div>

            <div class="p-4 sm:p-8 bg-white shadow-sm sm:rounded-lg">
                <div class="max-w-xl">
                    @include('profile.partials.delete-user-form')
                </div>
            </div>
        </div>
    </div>
@endsection
