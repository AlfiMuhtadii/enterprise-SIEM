<section>
    <header>
        <h2 class="text-lg font-medium text-cyan-50">
            {{ __('Profile Information') }}
        </h2>

        <p class="mt-1 text-sm text-cyan-100/75">
            {{ __("Update your account's profile information and email address.") }}
        </p>
    </header>

    <form id="send-verification" method="post" action="{{ route('verification.send') }}">
        @csrf
    </form>

    <form method="post" action="{{ route('profile.update') }}" class="mt-6 space-y-6">
        @csrf
        @method('patch')

        <div>
            <x-input-label for="name" :value="__('Name')" />
            <x-text-input id="name" name="name" type="text" class="mt-1 block w-full" :value="old('name', $user->name)" required autofocus autocomplete="name" />
            <x-input-error class="mt-2" :messages="$errors->get('name')" />
        </div>

        <div>
            <x-input-label for="email" :value="__('Email')" />
            <x-text-input id="email" name="email" type="email" class="mt-1 block w-full" :value="old('email', $user->email)" required autocomplete="username" />
            <x-input-error class="mt-2" :messages="$errors->get('email')" />

            @if ($user instanceof \Illuminate\Contracts\Auth\MustVerifyEmail && ! $user->hasVerifiedEmail())
                <div>
                    <p class="mt-2 text-sm text-cyan-100/80">
                        {{ __('Your email address is unverified.') }}

                        <button form="send-verification" class="rounded-md text-sm text-cyan-200 underline hover:text-cyan-100 focus:outline-none focus:ring-2 focus:ring-cyan-300 focus:ring-offset-2 focus:ring-offset-slate-900">
                            {{ __('Click here to re-send the verification email.') }}
                        </button>
                    </p>

                    @if (session('status') === 'verification-link-sent')
                        <p class="mt-2 rounded-lg border border-emerald-200/30 bg-emerald-200/10 px-3 py-2 text-sm font-medium text-emerald-200">
                            {{ __('A new verification link has been sent to your email address.') }}
                        </p>
                    @endif
                </div>
            @endif
        </div>

        <div>
            <x-input-label for="locale" :value="__('Language')" />
            <select id="locale" name="locale" class="mt-1 block w-full rounded-xl border border-cyan-100/35 bg-cyan-100/5 px-3 py-2 text-sm text-cyan-50 shadow-sm focus:border-cyan-300 focus:ring-cyan-300">
                <option value="" @selected(old('locale', $user->locale) === null)>{{ __('Browser / session default') }}</option>
                @foreach (\App\Http\Middleware\SetUserLocale::SUPPORTED_LOCALES as $code)
                    <option value="{{ $code }}" @selected(old('locale', $user->locale) === $code)>{{ strtoupper($code) }}</option>
                @endforeach
            </select>
            <x-input-error class="mt-2" :messages="$errors->get('locale')" />
        </div>

        <div class="flex items-center gap-4">
            <x-primary-button>{{ __('Save') }}</x-primary-button>

            @if (session('status') === 'profile-updated')
                <p
                    x-data="{ show: true }"
                    x-show="show"
                    x-transition
                    x-init="setTimeout(() => show = false, 2000)"
                    class="text-sm text-cyan-100/80"
                >{{ __('Saved.') }}</p>
            @endif
        </div>
    </form>
</section>
