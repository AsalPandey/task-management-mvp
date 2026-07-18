<x-guest-layout>
    <form method="POST" action="{{ route('setup.store') }}">
        @csrf

        <div>
            <x-input-label for="setup_token" value="Setup Token" />
            <x-text-input id="setup_token" class="block mt-1 w-full" type="password" name="setup_token" required autofocus />
            <x-input-error :messages="$errors->get('setup_token')" class="mt-2" />
        </div>

        <div class="mt-4">
            <x-input-label for="company_name" value="Company Name" />
            <x-text-input id="company_name" class="block mt-1 w-full" type="text" name="company_name" :value="old('company_name')" required />
            <x-input-error :messages="$errors->get('company_name')" class="mt-2" />
        </div>

        <div class="mt-4">
            <x-input-label for="name" value="First Manager Name" />
            <x-text-input id="name" class="block mt-1 w-full" type="text" name="name" :value="old('name')" required />
            <x-input-error :messages="$errors->get('name')" class="mt-2" />
        </div>

        <div class="mt-4">
            <x-input-label for="email" value="First Manager Email" />
            <x-text-input id="email" class="block mt-1 w-full" type="email" name="email" :value="old('email')" required />
            <x-input-error :messages="$errors->get('email')" class="mt-2" />
        </div>

        <div class="mt-4">
            <x-input-label for="password" value="Password" />
            <x-text-input id="password" class="block mt-1 w-full" type="password" name="password" required />
            <x-input-error :messages="$errors->get('password')" class="mt-2" />
        </div>

        <div class="mt-4">
            <x-input-label for="password_confirmation" value="Confirm Password" />
            <x-text-input id="password_confirmation" class="block mt-1 w-full" type="password" name="password_confirmation" required />
        </div>

        <div class="mt-4">
            <x-input-label for="timezone" value="Timezone" />
            <x-text-input id="timezone" class="block mt-1 w-full" type="text" name="timezone" value="{{ old('timezone', 'Asia/Kathmandu') }}" required />
            <x-input-error :messages="$errors->get('timezone')" class="mt-2" />
        </div>

        <div class="mt-4">
            <x-input-label for="app_url" value="Application URL" />
            <x-text-input id="app_url" class="block mt-1 w-full" type="url" name="app_url" :value="old('app_url', config('app.url'))" />
            <x-input-error :messages="$errors->get('app_url')" class="mt-2" />
        </div>

        <div class="flex items-center justify-end mt-4">
            <x-primary-button>Install</x-primary-button>
        </div>
    </form>
</x-guest-layout>
