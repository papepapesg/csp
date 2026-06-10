<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * FE-APP-02/03 (/m Field) + FE-APP-04 (/care self-care) are installable PWAs:
 * each shell links its OWN manifest (correct identity/scope/start_url), carries
 * iOS meta, and the shared service worker registers per scope with an offline
 * shell per app.
 */
class PwaShellTest extends TestCase
{
    public function test_field_shell_links_its_manifest_and_icons(): void
    {
        $html = $this->get('/m')->assertOk()->getContent();
        $this->assertStringContainsString('rel="manifest" href="/manifest.webmanifest"', $html);
        $this->assertStringContainsString('apple-touch-icon', $html);
        $this->assertStringContainsString('theme-color', $html);
    }

    public function test_care_shell_links_its_own_manifest_not_the_field_one(): void
    {
        $html = $this->get('/care')->assertOk()->getContent();
        $this->assertStringContainsString('rel="manifest" href="/care.webmanifest"', $html);
        $this->assertStringNotContainsString('href="/manifest.webmanifest"', $html);
        $this->assertStringContainsString('apple-touch-icon', $html);
    }

    public function test_manifests_declare_distinct_identities_and_scopes(): void
    {
        $field = json_decode(file_get_contents(public_path('manifest.webmanifest')), true);
        $care = json_decode(file_get_contents(public_path('care.webmanifest')), true);

        $this->assertSame(['/m', '/m', 'standalone'], [$field['start_url'], $field['scope'], $field['display']]);
        $this->assertSame(['/care', '/care', 'standalone'], [$care['start_url'], $care['scope'], $care['display']]);
        $this->assertNotSame($field['name'], $care['name']);

        // Install criteria: a 192 and a 512 icon, files actually present.
        foreach ([$field, $care] as $manifest) {
            $sizes = array_column($manifest['icons'], 'sizes');
            $this->assertContains('192x192', $sizes);
            $this->assertContains('512x512', $sizes);
            foreach ($manifest['icons'] as $icon) {
                $this->assertFileExists(public_path(ltrim($icon['src'], '/')));
            }
        }
    }

    public function test_service_worker_covers_both_scopes_with_per_app_offline_shell(): void
    {
        $sw = file_get_contents(public_path('sw.js'));
        $this->assertStringContainsString("c.addAll(['/m', '/care'])", $sw);
        $this->assertStringContainsString("url.pathname.startsWith('/care') ? '/care' : '/m'", $sw);

        // Each app registers the worker under its own scope.
        $this->assertStringContainsString("register('/sw.js', { scope: '/m' })", file_get_contents(resource_path('mobile/main.js')));
        $this->assertStringContainsString("register('/sw.js', { scope: '/care' })", file_get_contents(resource_path('care/main.js')));
    }
}
