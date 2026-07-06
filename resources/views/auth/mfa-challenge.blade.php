<x-guest-layout>
    <div class="mb-6 space-y-2 text-center">
        <span class="brand-chip">Detector Console</span>
        <h1 class="text-2xl font-semibold text-main-ui">Two-Factor Verification</h1>
        <p class="text-sm text-muted-ui">Enter the 6-digit code from your authenticator app.</p>
    </div>

    <x-input-error :messages="$errors->get('code')" class="mb-4" />

    <form method="POST" action="{{ route('mfa.verify') }}">
        @csrf
        <div>
            <x-input-label for="code" value="Authentication Code" />
            <x-text-input id="code" class="block mt-1 w-full mono-ui" type="text" name="code"
                          inputmode="numeric" pattern="[0-9]*" autocomplete="one-time-code"
                          maxlength="6" required autofocus />
        </div>

        <div class="flex items-center justify-end mt-4">
            <x-primary-button>Verify</x-primary-button>
        </div>
    </form>
</x-guest-layout>
