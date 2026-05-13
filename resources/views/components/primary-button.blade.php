<button {{ $attributes->merge(['type' => 'submit', 'class' => 'inline-flex items-center rounded-xl border border-emerald-200/30 bg-emerald-300/90 px-4 py-2 text-xs font-semibold uppercase tracking-widest text-slate-900 hover:bg-emerald-200 focus:bg-emerald-200 active:bg-emerald-300 focus:outline-none focus:ring-2 focus:ring-emerald-300 focus:ring-offset-2 focus:ring-offset-slate-900 transition ease-in-out duration-150']) }}>
    {{ $slot }}
</button>
