<button {{ $attributes->merge(['type' => 'submit', 'class' => 'inline-flex items-center rounded-xl border border-rose-300/30 bg-rose-400/90 px-4 py-2 text-xs font-semibold uppercase tracking-widest text-rose-950 hover:bg-rose-300 active:bg-rose-500 focus:outline-none focus:ring-2 focus:ring-rose-300 focus:ring-offset-2 focus:ring-offset-slate-900 transition ease-in-out duration-150']) }}>
    {{ $slot }}
</button>
