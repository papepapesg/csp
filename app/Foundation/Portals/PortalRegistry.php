<?php

namespace App\Foundation\Portals;

use App\Models\User;

/**
 * Resolves the active app "portal" from the request host (slug.<base_domain>) and exposes the
 * apps a principal may open. One backend serves every subdomain; this class decides which app's
 * shell/nav to render and builds the launcher's tile list. Data lives in config/portals.php.
 */
class PortalRegistry
{
    public static function baseDomain(): string
    {
        return (string) config('portals.base_domain', 'localhost');
    }

    /** @return array<string,array<string,mixed>> */
    public static function apps(): array
    {
        return (array) config('portals.apps', []);
    }

    /** The subdomain slug for a host, or null when it's the bare base / launcher host. */
    public static function slugForHost(?string $host): ?string
    {
        $host = strtolower((string) $host);
        $base = strtolower(self::baseDomain());
        if ($host === '' || $host === $base) {
            return null;
        }
        $suffix = '.'.$base;
        if (str_ends_with($host, $suffix)) {
            $slug = substr($host, 0, -strlen($suffix));
        } else {
            // dev/local without the configured base (e.g. localhost, raw IP) → treat as launcher
            return null;
        }
        if ($slug === '' || $slug === (string) config('portals.launcher_slug', 'app')) {
            return null;
        }

        return $slug;
    }

    /** The app definition (with slug) for a host, or null for the launcher. */
    public static function currentForHost(?string $host): ?array
    {
        $slug = self::slugForHost($host);
        if ($slug === null) {
            return null;
        }
        $app = self::apps()[$slug] ?? null;

        return $app ? ['slug' => $slug] + $app : null;
    }

    /** True when the user may open the given app (perm null = open to any authenticated user). */
    public static function canAccess(?User $user, array $app): bool
    {
        $perm = $app['perm'] ?? null;
        if ($perm === null) {
            return true;
        }
        if (! $user) {
            return false;
        }

        return $user->hasRole('SUPER_ADMIN') || $user->can($perm);
    }

    /**
     * The launcher tiles a user may open: each app with an absolute URL to its subdomain.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function accessibleTiles(?User $user, string $scheme = 'https'): array
    {
        $base = self::baseDomain();
        $tiles = [];
        foreach (self::apps() as $slug => $app) {
            if (! self::canAccess($user, $app)) {
                continue;
            }
            $tiles[] = [
                'slug' => $slug,
                'label' => $app['label'],
                'tagline' => $app['tagline'] ?? '',
                'color' => $app['color'] ?? '#4f46e5',
                'url' => "{$scheme}://{$slug}.{$base}",
            ];
        }

        return $tiles;
    }

    /** The first reachable route name in an app's nav (for app-root redirects). */
    public static function firstRoute(array $app): ?string
    {
        foreach ($app['nav'] ?? [] as [$group, $items]) {
            foreach ($items as [$route, $label, $perm]) {
                return $route;
            }
        }

        return null;
    }
}
