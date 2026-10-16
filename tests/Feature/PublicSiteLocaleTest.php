<?php

namespace Tests\Feature;

use Tests\IsolatesDatabase;
use Tests\TestCase;

/**
 * i18n Phase 4 — the public marketing site renders its chrome from
 * `lang/en/site.php` and honours the `locale` middleware. CMS page bodies,
 * nav/footer links and taglines stay deployment content and are not asserted
 * here.
 */
class PublicSiteLocaleTest extends TestCase
{
    use IsolatesDatabase;

    public function test_the_english_site_catalog_is_the_source_of_truth(): void
    {
        $this->assertSame('Skip to content', __('site.skipToContent'));
        $this->assertSame('Blog', __('site.blog.title'));
        $this->assertSame('Page not found', __('site.notFound.title'));
        $this->assertNotSame('', __('site.contact.error'));
    }

    public function test_public_pages_default_to_english(): void
    {
        $response = $this->get('/');

        $response->assertOk();
        $this->assertSame('en', $response->headers->get('Content-Language'));
        $response->assertSee('<html lang="en">', false);
        $response->assertSee('Skip to content', false);
    }

    public function test_a_locale_query_param_switches_the_public_site_locale(): void
    {
        $response = $this->get('/?locale=es');

        $response->assertOk();
        $this->assertSame('es', $response->headers->get('Content-Language'));
        $response->assertSee('Saltar al contenido', false);
        $response->assertDontSee('Skip to content', false);
    }

    public function test_the_layout_ships_contact_copy_for_site_js(): void
    {
        $response = $this->get('/');

        $response->assertOk();
        $response->assertSee('id="site-i18n"', false);
        $response->assertSee('Something went wrong', false);
        $response->assertSee('We usually reply within one business day.', false);
    }
}
