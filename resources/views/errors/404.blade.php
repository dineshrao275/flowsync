@extends('layouts.site', [
    'title' => __('site.notFound.title'),
    'seoTitle' => __('site.notFound.title'),
    'metaDescription' => __('site.notFound.description'),
])

@section('content')
    <section class="relative overflow-hidden">
        <div class="pointer-events-none absolute inset-0 -z-10" aria-hidden="true">
            <div class="absolute inset-0 bg-gradient-to-b from-blue-50 via-white to-white"></div>
            <div class="absolute -top-32 left-1/2 h-96 w-[52rem] -translate-x-1/2 rounded-full bg-blue-200/40 blur-3xl"></div>
        </div>
        <div class="mx-auto max-w-6xl px-4 py-24 text-center sm:px-6 sm:py-32">
            <p class="text-sm font-bold uppercase tracking-widest text-blue-600">404</p>
            <h1 class="mx-auto mt-3 max-w-xl text-4xl font-extrabold tracking-tight text-balance text-slate-900 sm:text-5xl">{{ __('site.notFound.heading') }}</h1>
            <p class="mx-auto mt-4 max-w-md text-lg text-slate-600">{{ __('site.notFound.body') }}</p>
            <div class="mt-8 flex flex-col items-center justify-center gap-3 sm:flex-row">
                <x-site.button href="/" size="lg">{{ __('site.notFound.home') }}</x-site.button>
                <x-site.button href="/app/login" variant="ghost" size="lg">{{ __('site.actions.logIn') }}</x-site.button>
            </div>
        </div>
    </section>
@endsection
