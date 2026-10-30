@props(['media' => null, 'eager' => false])

{{-- A product screenshot in a browser window: the chrome makes it read as "the real app", not a stock image. --}}
<figure {{ $attributes->merge(['class' => 'overflow-hidden rounded-2xl border border-slate-200/80 bg-white shadow-2xl shadow-slate-900/10 ring-1 ring-slate-900/5']) }}>
    <div class="flex items-center gap-2 border-b border-slate-200/80 bg-slate-50 px-4 py-2.5" aria-hidden="true">
        <span class="h-2.5 w-2.5 rounded-full bg-red-400/80"></span>
        <span class="h-2.5 w-2.5 rounded-full bg-amber-400/80"></span>
        <span class="h-2.5 w-2.5 rounded-full bg-emerald-400/80"></span>
        <span class="ml-3 hidden flex-1 truncate rounded-md bg-white px-3 py-1 text-xs text-slate-400 ring-1 ring-inset ring-slate-200 sm:block">{{ request()->getHost() }}/app</span>
    </div>
    <x-site.img :media="$media" :eager="$eager" class="block w-full" />
</figure>
