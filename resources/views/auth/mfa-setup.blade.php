<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-cyan-100 leading-tight">Enable Two-Factor Authentication</h2>
    </x-slot>

    <div class="py-6 px-4 max-w-2xl mx-auto space-y-6">
        <section class="glass-card p-5">
            <p class="text-sm text-muted-ui">
                Scan this into any TOTP authenticator app (Google Authenticator, Authy, 1Password, ...),
                or enter the secret manually, then confirm with the 6-digit code it generates.
            </p>

            <div class="mt-4 rounded border border-cyan-200/20 bg-slate-950 px-3 py-2">
                <p class="text-xs text-muted-ui">Manual entry secret:</p>
                <p class="mono-ui text-cyan-50 break-all">{{ $secret }}</p>
            </div>

            <div class="mt-2 rounded border border-cyan-200/20 bg-slate-950 px-3 py-2">
                <p class="text-xs text-muted-ui">Provisioning URI (for QR-code generators):</p>
                <p class="mono-ui text-cyan-50 break-all text-xs">{{ $uri }}</p>
            </div>

            <x-input-error :messages="$errors->get('code')" class="mt-4" />

            <form method="POST" action="{{ route('mfa.enable') }}" class="mt-4">
                @csrf
                <x-input-label for="code" value="Enter the 6-digit code to confirm" />
                <x-text-input id="code" class="block mt-1 w-full mono-ui" type="text" name="code"
                              inputmode="numeric" pattern="[0-9]*" autocomplete="one-time-code"
                              maxlength="6" required autofocus />
                <x-primary-button class="mt-4">Enable MFA</x-primary-button>
            </form>
        </section>
    </div>
</x-app-layout>
