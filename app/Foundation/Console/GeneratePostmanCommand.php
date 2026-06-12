<?php

namespace App\Foundation\Console;

use Illuminate\Console\Command;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Routing\Router;
use Illuminate\Support\Str;

/**
 * Generates Postman v2.1 collections, one per bundle (DD_API-00 §9 spirit).
 *
 * Every API route is grouped into a bundle derived from its controller
 * namespace (Modules\<Bundle>\... => <Bundle>, App\Foundation => Foundation).
 * This guarantees the "all APIs have Postman collections by bundle" requirement
 * stays satisfied automatically as modules are added.
 */
class GeneratePostmanCommand extends Command
{
    protected $signature = 'sophix:postman:generate {--output=postman/collections}';

    protected $description = 'Generate per-bundle Postman collections from registered API routes';

    public function handle(Router $router): int
    {
        $outputDir = base_path((string) $this->option('output'));
        if (! is_dir($outputDir)) {
            mkdir($outputDir, 0o755, true);
        }

        $bundles = [];

        foreach ($router->getRoutes() as $route) {
            if (! Str::startsWith($route->uri(), 'api/')) {
                continue;
            }

            $bundle = $this->bundleFor($route);
            $bundles[$bundle][] = $this->requestItem($route);
        }

        ksort($bundles);

        foreach ($bundles as $bundle => $items) {
            $slug = Str::slug($bundle);
            $collection = [
                'info' => [
                    'name' => "SOPHIX BSS — {$bundle}",
                    'schema' => 'https://schema.getpostman.com/json/collection/v2.1.0/collection.json',
                    '_postman_id' => (string) Str::uuid(),
                    'description' => "Auto-generated API collection for the {$bundle} bundle. Regenerate with `php artisan sophix:postman:generate`.",
                ],
                'auth' => [
                    'type' => 'bearer',
                    'bearer' => [['key' => 'token', 'value' => '{{access_token}}', 'type' => 'string']],
                ],
                'item' => array_values($items),
            ];

            $path = "{$outputDir}/{$slug}.postman_collection.json";
            file_put_contents($path, json_encode($collection, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
            $this->line("  <info>✓</info> {$bundle} (".count($items).' requests) -> '.Str::after($path, base_path().'/'));
        }

        $this->info('Generated '.count($bundles).' bundle collection(s).');
        $this->zipKit($outputDir);

        return self::SUCCESS;
    }

    /** One importable zip: every collection + environment (Postman accepts the zip as-is). */
    private function zipKit(string $outputDir): void
    {
        $zipPath = dirname($outputDir).'/SOPHIX-postman.zip';
        $zip = new \ZipArchive;
        if ($zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            $this->warn('Could not write '.$zipPath);

            return;
        }
        foreach (glob($outputDir.'/*.json') as $file) {
            $zip->addFile($file, basename($file));
        }
        foreach (glob(dirname($outputDir).'/*.postman_environment.json') as $file) {
            $zip->addFile($file, basename($file));
        }
        $count = $zip->numFiles;
        $zip->close();
        $this->line("  <info>✓</info> kit zip ({$count} files) -> ".Str::after($zipPath, base_path().'/'));
    }

    private function bundleFor(RoutingRoute $route): string
    {
        $action = $route->getActionName();

        if (preg_match('/^Modules\\\\([^\\\\]+)\\\\/', $action, $m)) {
            return $m[1];
        }

        if (Str::contains($action, 'App\\Foundation')) {
            return 'Foundation';
        }

        return 'Platform';
    }

    /** @return array<string, mixed> */
    private function requestItem(RoutingRoute $route): array
    {
        $method = collect($route->methods())->first(fn ($m) => ! in_array($m, ['HEAD', 'OPTIONS'], true)) ?? 'GET';
        $uri = $route->uri();
        $isCommand = in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true);

        $headers = [
            ['key' => 'Accept', 'value' => 'application/json'],
            ['key' => 'X-Operator-Code', 'value' => '{{operator_code}}'],
        ];
        if ($isCommand) {
            $headers[] = ['key' => 'Content-Type', 'value' => 'application/json'];
            $headers[] = ['key' => 'Idempotency-Key', 'value' => '{{$guid}}'];
            $headers[] = ['key' => 'X-Correlation-Id', 'value' => '{{$guid}}'];
        }

        $request = [
            'method' => $method,
            'header' => $headers,
            'url' => [
                'raw' => '{{base_url}}/'.$uri,
                'host' => ['{{base_url}}'],
                'path' => explode('/', $uri),
            ],
        ];

        if ($isCommand) {
            $request['body'] = [
                'mode' => 'raw',
                'raw' => "{\n}",
                'options' => ['raw' => ['language' => 'json']],
            ];
        }

        return [
            'name' => strtoupper($method).' /'.$uri,
            'request' => $request,
        ];
    }
}
