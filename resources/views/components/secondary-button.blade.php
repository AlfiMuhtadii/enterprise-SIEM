<button {{ $attributes->merge(['type' => 'button', 'class' => 'inline-flex items-center rounded-xl border border-cyan-100/35 bg-cyan-100/10 px-4 py-2 text-xs font-semibold uppercase tracking-widest text-cyan-50 shadow-sm hover:bg-cyan-100/20 focus:outline-none focus:ring-2 focus:ring-cyan-200 focus:ring-offset-2 focus:ring-offset-slate-900 disabled:opacity-25 transition ease-in-out duration-150']) }}>
    {{ $slot }}
</button>
