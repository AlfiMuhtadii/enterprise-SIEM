<x-app-layout>
    <x-slot name="header">
        <div>
            <p class="brand-chip">Account</p>
            <h2 class="mt-2 text-2xl font-semibold leading-tight text-main-ui">{{ __('Profile') }}</h2>
        </div>
    </x-slot>

    <div class="space-y-6">
        <div class="glass-card p-4 sm:p-8">
            <div class="max-w-xl">
                @include('profile.partials.update-profile-information-form')
            </div>
        </div>

        <div class="glass-card p-4 sm:p-8">
            <div class="max-w-xl">
                @include('profile.partials.update-password-form')
            </div>
        </div>

        <div class="glass-card p-4 sm:p-8">
            <div class="max-w-xl">
                @include('profile.partials.delete-user-form')
            </div>
        </div>
    </div>
</x-app-layout>
