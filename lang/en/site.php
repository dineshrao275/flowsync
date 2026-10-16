<?php

/*
 * Public marketing site copy (i18n Phase 4). Only the *template chrome* lives
 * here — page/block bodies, nav and footer links, taglines and testimonials are
 * CMS content authored per deployment and are never translated by code. Keys
 * hold the English source of truth; a locale without this file falls back to
 * en via `config('app.fallback_locale')`.
 */
return [
    'skipToContent' => 'Skip to content',

    'brand' => [
        'home' => ':name home',
    ],

    'nav' => [
        'primary' => 'Primary',
        'mobile' => 'Mobile',
        'toggleMenu' => 'Toggle menu',
    ],

    'actions' => [
        'logIn' => 'Log in',
        'startFree' => 'Start free',
        'bookDemo' => 'Book a demo',
    ],

    'footer' => [
        'copyright' => '© :year :name. All rights reserved.',
        'privacy' => 'Privacy',
        'terms' => 'Terms',
    ],

    'blocks' => [
        'readyToStart' => 'Ready to start?',
    ],

    'pricing' => [
        'heading' => 'Simple pricing',
        'mostPopular' => 'Most popular',
        'moreIncluded' => '+:count more included',
        'free' => 'Free',
        'perMonth' => '/ mo',
        'billedAnnually' => ', billed annually',
        'trialDays' => ':count-day free trial',
    ],

    'faq' => [
        'heading' => 'Questions, answered',
    ],

    'video' => [
        'title' => 'Video',
        'watch' => 'Watch the video',
    ],

    'comparison' => [
        'included' => 'Included',
        'notIncluded' => 'Not included',
    ],

    'contact' => [
        'heading' => 'Talk to us',
        'name' => 'Name',
        'email' => 'Work email',
        'company' => 'Company',
        'optional' => '(optional)',
        'message' => 'How can we help?',
        'submitDemo' => 'Book my demo',
        'submit' => 'Send message',
        'error' => 'Something went wrong — please try again.',
        'thanks' => 'Thanks — we will be in touch.',
        'thanksBody' => 'We usually reply within one business day.',
    ],

    'blog' => [
        'title' => 'Blog',
        'category' => 'Category',
        'tagline' => 'Guides, changelogs, and notes from the team.',
        'empty' => 'No posts yet — check back soon.',
        'minRead' => ':count min read',
        'by' => 'By :name',
        'relatedLabel' => 'Related posts',
        'keepReading' => 'Keep reading',
        'newer' => '← Newer',
        'older' => 'Older →',
        'pageOf' => 'Page :current of :last',
        'pages' => 'Blog pages',
    ],

    'notFound' => [
        'title' => 'Page not found',
        'description' => 'The page you are looking for does not exist.',
        'heading' => 'This page wandered off the board',
        'body' => "The link may be broken, or the page may have moved. Let's get you back in motion.",
        'home' => 'Back to home',
    ],
];
