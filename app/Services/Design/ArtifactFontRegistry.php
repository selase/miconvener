<?php

declare(strict_types=1);

namespace App\Services\Design;

use App\Models\ArtifactFont;
use App\Services\Tenancy\TenantContext;
use Barryvdh\DomPDF\PDF;
use Dompdf\Dompdf;
use Dompdf\FontMetrics;
use Dompdf\Options;
use FontLib\Font;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

final class ArtifactFontRegistry
{
    /** @var array<string, array<int, string>> */
    private const array BUNDLED = [
        'Helvetica' => [], 'Times' => [], 'Courier' => [],
        'DejaVu Sans' => [400 => 'DejaVuSans.ttf', 700 => 'DejaVuSans-Bold.ttf'],
        'DejaVu Serif' => [400 => 'DejaVuSerif.ttf', 700 => 'DejaVuSerif-Bold.ttf'],
        'DejaVu Sans Mono' => [400 => 'DejaVuSansMono.ttf', 700 => 'DejaVuSansMono-Bold.ttf'],
        'Lato' => [400 => 'lato-Lato-Regular.ttf', 700 => 'lato-Lato-Bold.ttf'],
        'Arvo' => [400 => 'arvo-Arvo-Regular.ttf', 700 => 'arvo-Arvo-Bold.ttf'],
        'PT Serif' => [400 => 'ptserif-PT_Serif-Web-Regular.ttf', 700 => 'ptserif-PT_Serif-Web-Bold.ttf'],
        'PT Sans' => [400 => 'ptsans-PT_Sans-Web-Regular.ttf', 700 => 'ptsans-PT_Sans-Web-Bold.ttf'],
        'Abel' => [400 => 'abel-Abel-Regular.ttf'], 'Anton' => [400 => 'anton-Anton-Regular.ttf'],
    ];

    /** @var array<string, FontMetrics> */
    private array $preparedMetrics = [];

    /** @var array<string, string> */
    private array $preparedFiles = [];

    /** @return list<array<string, mixed>> */
    public function catalog(string $tenantId, string $eventId): array
    {
        $fonts = [];
        foreach (self::BUNDLED as $family => $paths) {
            $fonts[] = $this->describe($family, $family, array_keys($paths) ?: [400, 700], $eventId);
        }
        foreach (ArtifactFont::withoutGlobalScopes()->where('tenant_id', $tenantId)->orderBy('name')->get() as $font) {
            $fonts[] = $this->describe($font->family, $font->name, array_map('intval', array_keys($font->facesSettings())), $eventId) + ['id' => $font->id, 'archived' => $font->archived_at !== null];
        }

        return $fonts;
    }

    /**
     * @param  list<int>  $weights
     * @return array<string, mixed>
     */
    public function describe(string $family, string $name, array $weights, string $eventId): array
    {
        return ['family' => $family, 'name' => $name, 'weights' => $weights, 'faces' => array_map(fn (int $weight): array => [
            'weight' => $weight,
            'url' => route('tenant.events.artifact-fonts.face', ['event' => $eventId, 'family' => $family, 'weight' => $weight]),
        ], isset(self::BUNDLED[$family]) && self::BUNDLED[$family] === [] ? [] : $weights)];
    }

    public function allows(string $family, ?string $tenantId = null): bool
    {
        if (array_key_exists($family, self::BUNDLED)) {
            return true;
        }
        $tenantId ??= app(TenantContext::class)->activeTenantId();

        return $tenantId !== null && ArtifactFont::withoutGlobalScopes()->where('tenant_id', $tenantId)->where('family', $family)->exists();
    }

    public function allowsWeight(string $family, int $weight, ?string $tenantId = null): bool
    {
        if (in_array($family, ['Helvetica', 'Times', 'Courier', 'DejaVu Sans', 'DejaVu Serif', 'DejaVu Sans Mono'], true)) {
            return in_array($weight, [400, 500, 600, 700, 800], true);
        }
        if (isset(self::BUNDLED[$family])) {
            return isset(self::BUNDLED[$family][$weight]);
        }
        $tenantId ??= app(TenantContext::class)->activeTenantId();
        $font = ArtifactFont::withoutGlobalScopes()->where('tenant_id', $tenantId)->where('family', $family)->first();

        return isset($font?->facesSettings()[$weight]);
    }

    public function path(string $family, int $weight, ?string $tenantId = null): string
    {
        if (isset(self::BUNDLED[$family][$weight])) {
            $file = self::BUNDLED[$family][$weight];

            return str_starts_with($file, 'DejaVu') ? base_path('vendor/dompdf/dompdf/lib/fonts/'.$file) : public_path('assets/fonts/artifacts/'.$file);
        }
        $tenantId ??= app(TenantContext::class)->activeTenantId();
        $key = $tenantId.'|'.$family.'|'.$weight;
        if (isset($this->preparedFiles[$key])) {
            return $this->preparedFiles[$key];
        }
        $font = $tenantId === null ? null : ArtifactFont::withoutGlobalScopes()->where('tenant_id', $tenantId)->where('family', $family)->first();
        $face = $font?->facesSettings()[$weight] ?? null;
        if (! is_array($face)) {
            throw ValidationException::withMessages(['layout' => 'This font or weight is unavailable for this organization.']);
        }
        $bytes = Storage::disk($font->disk)->get($face['path']);
        if (! is_string($bytes) || hash('sha256', $bytes) !== $face['hash']) {
            throw new RuntimeException('The original font file is unavailable. Restore it before printing this design.');
        }
        $path = storage_path('fonts/artifacts/files/'.$face['hash'].'.ttf');
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0755, true);
        }
        if (! is_file($path) || hash_file('sha256', $path) !== $face['hash']) {
            file_put_contents($path, $bytes, LOCK_EX);
        }

        return $this->preparedFiles[$key] = $path;
    }

    public function metrics(string $family, ?string $tenantId = null): FontMetrics
    {
        $tenantId ??= app(TenantContext::class)->activeTenantId();
        $key = $tenantId.'|'.$family;
        if (isset($this->preparedMetrics[$key])) {
            return $this->preparedMetrics[$key];
        }
        if (in_array($family, ['Helvetica', 'Times', 'Courier', 'DejaVu Sans', 'DejaVu Serif', 'DejaVu Sans Mono'], true)) {
            return $this->preparedMetrics[$key] = (new Dompdf)->getFontMetrics();
        }
        $weights = array_keys(self::BUNDLED[$family] ?? []);
        if ($weights === []) {
            $tenantId ??= app(TenantContext::class)->activeTenantId();
            $font = ArtifactFont::withoutGlobalScopes()->where('tenant_id', $tenantId)->where('family', $family)->firstOrFail();
            $weights = array_keys($font->facesSettings());
        }
        $directory = storage_path('fonts/artifacts/metrics/'.hash('sha256', $family));
        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        return $this->preparedMetrics[$key] = Cache::lock('artifact-font-metrics-'.hash('sha256', $family), 30)->block(10, function () use ($family, $weights, $tenantId, $directory): FontMetrics {
            $options = new Options;
            $options->setFontDir($directory);
            $options->setChroot([base_path(), storage_path()]);
            $metrics = (new Dompdf($options))->getFontMetrics();
            foreach ($weights as $weight) {
                if (! $metrics->registerFont(['family' => $family, 'weight' => (int) $weight, 'style' => 'normal'], $this->path($family, (int) $weight, $tenantId))) {
                    throw new RuntimeException('This font could not be prepared for printing.');
                }
            }

            return $metrics;
        });
    }

    /** @param array<string, array<string, bool|float|int|string>> $layout */
    public function preparePdf(PDF $pdf, array $layout, string $tenantId): PDF
    {
        $families = array_unique(array_column($layout, 'font_family'));
        foreach ($families as $family) {
            if (! in_array($family, ['Helvetica', 'Times', 'Courier', 'DejaVu Sans', 'DejaVu Serif', 'DejaVu Sans Mono'], true)) {
                $metrics = $this->metrics($family, $tenantId);
                $pdf->getDomPDF()->getFontMetrics()->setFontFamily(mb_strtolower($family), $metrics->getFontFamilies()[mb_strtolower($family)]);
            }
        }

        return $pdf;
    }

    public function upload(UploadedFile $file, string $tenantId, ?string $name = null): ArtifactFont
    {
        return $this->store([$this->validateBytes(file_get_contents($file->getRealPath()))], $tenantId, $name);
    }

    public function import(string $source, string $tenantId): ArtifactFont
    {
        $family = $source;
        if (filter_var($source, FILTER_VALIDATE_URL)) {
            $url = parse_url($source);
            if (($url['scheme'] ?? '') !== 'https' || ! in_array($url['host'] ?? '', ['fonts.google.com', 'fonts.googleapis.com', 'fonts.gstatic.com'], true) || isset($url['user']) || isset($url['port'])) {
                throw ValidationException::withMessages(['source' => 'Use a Google Fonts family name or Google Fonts link, or upload a TTF file.']);
            }
            if ($url['host'] === 'fonts.gstatic.com') {
                return $this->store([$this->validateBytes($this->download($source))], $tenantId);
            }
            if ($url['host'] === 'fonts.google.com') {
                $family = urldecode(basename($url['path'] ?? ''));
            } else {
                parse_str($url['query'] ?? '', $query);
                $family = explode(':', (string) ($query['family'] ?? ''))[0];
            }
        }
        $family = str_replace('+', ' ', $family);
        if (preg_match('/\A[A-Za-z][A-Za-z0-9 -]{0,79}\z/', $family) !== 1) {
            throw ValidationException::withMessages(['source' => 'Enter one Google Fonts family name or its link.']);
        }
        $css = $this->download('https://fonts.googleapis.com/css?family='.rawurlencode($family).':400,700');
        preg_match_all('/url\((https:\/\/fonts\.gstatic\.com\/[^)\s]+)\)/', $css, $matches);
        $faces = [];
        foreach (array_slice(array_unique($matches[1]), 0, 4) as $url) {
            $face = $this->validateBytes($this->download($url));
            $faces[$face['weight']] = $face;
        }
        if ($faces === []) {
            throw ValidationException::withMessages(['source' => 'This family has no supported static TrueType files. Upload a static TTF instead.']);
        }

        return $this->store(array_values($faces), $tenantId, $family);
    }

    private function download(string $url): string
    {
        try {
            $response = Http::timeout(15)->connectTimeout(5)->withHeaders(['User-Agent' => 'Mozilla/5.0'])->withOptions(['allow_redirects' => false, 'progress' => function (float $total, float $downloaded): void {
                if ($downloaded > 4 * 1024 * 1024) {
                    throw new RuntimeException('Font download is too large.');
                }
            }, 'on_headers' => function (\Psr\Http\Message\ResponseInterface $response): void {
                if ((int) $response->getHeaderLine('Content-Length') > 4 * 1024 * 1024) {
                    throw new RuntimeException('Font download is too large.');
                }
            }])->get($url);
            if (! $response->successful() || mb_strlen($response->body(), '8bit') > 4 * 1024 * 1024) {
                throw new RuntimeException('Font download failed.');
            }

            return $response->body();
        } catch (Throwable) {
            throw ValidationException::withMessages(['source' => 'The font could not be downloaded. Check the family or upload its TTF file.']);
        }
    }

    /** @return array{bytes: string, weight: int, name: string} */
    private function validateBytes(string $bytes): array
    {
        $temporary = tempnam(sys_get_temp_dir(), 'artifact-font-');
        try {
            if (mb_strlen($bytes, '8bit') < 64 || mb_strlen($bytes, '8bit') > 4 * 1024 * 1024 || mb_substr($bytes, 0, 4, '8bit') !== "\x00\x01\x00\x00") {
                throw new RuntimeException('Not a static TrueType font.');
            }
            $tables = unpack('n', mb_substr($bytes, 4, 2, '8bit'))[1];
            if ($tables > 128 || 12 + $tables * 16 > mb_strlen($bytes, '8bit')) {
                throw new RuntimeException('Invalid font tables.');
            }
            $tags = [];
            for ($index = 0; $index < $tables; $index++) {
                $record = mb_substr($bytes, 12 + $index * 16, 16, '8bit');
                $tag = mb_substr($record, 0, 4, '8bit');
                $data = unpack('Noffset/Nlength', mb_substr($record, 8, null, '8bit'));
                if (mb_strlen($bytes, '8bit') < $data['offset'] + $data['length']) {
                    throw new RuntimeException('Truncated font.');
                }
                $tags[] = $tag;
            }
            if (in_array('fvar', $tags, true) || array_diff(['head', 'hhea', 'hmtx', 'maxp', 'cmap', 'name', 'glyf', 'loca', 'OS/2'], $tags) !== []) {
                throw new RuntimeException('Unsupported font tables.');
            }
            file_put_contents($temporary, $bytes);
            $font = Font::load($temporary);
            $font->parse();
            $weight = (int) $font->getFontWeight();
            $name = (string) $font->getFontName();
            if (! in_array($weight, [400, 700], true) || $font->getUnicodeCharMap() === []) {
                throw new RuntimeException('Use a regular or bold font with Unicode characters.');
            }
            $font->close();

            return ['bytes' => $bytes, 'weight' => $weight, 'name' => mb_substr($name, 0, 80)];
        } catch (Throwable) {
            throw ValidationException::withMessages(['font' => 'Use a valid static TrueType (.ttf) regular or bold font, up to 4 MB.']);
        } finally {
            if (is_string($temporary)) {
                unlink($temporary);
            }
        }
    }

    /** @param list<array{bytes: string, weight: int, name: string}> $faces */
    private function store(array $faces, string $tenantId, ?string $name = null): ArtifactFont
    {
        $id = (string) Str::uuid();
        $disk = config('app.env') === 'production' ? 's3' : 'local';
        $stored = [];
        try {
            foreach ($faces as $face) {
                $path = 'tenants/'.$tenantId.'/artifact-fonts/'.$id.'/'.$face['weight'].'.ttf';
                if (! Storage::disk($disk)->put($path, $face['bytes'])) {
                    throw new RuntimeException('Font could not be stored.');
                }
                $stored[$face['weight']] = ['path' => $path, 'hash' => hash('sha256', $face['bytes'])];
            }

            return ArtifactFont::query()->create(['tenant_id' => $tenantId, 'family' => 'artifact-'.$id, 'name' => $name ?: $faces[0]['name'], 'disk' => $disk, 'faces' => $stored]);
        } catch (Throwable $exception) {
            Storage::disk($disk)->deleteDirectory('tenants/'.$tenantId.'/artifact-fonts/'.$id);
            throw $exception;
        }
    }
}
