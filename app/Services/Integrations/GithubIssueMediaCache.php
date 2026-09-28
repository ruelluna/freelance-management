<?php

namespace App\Services\Integrations;

use App\Models\Issue;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class GithubIssueMediaCache
{
    public function cacheAndRewrite(Issue $issue, string $html, ?string $markdownBody = null): string
    {
        $markdownUuids = $this->extractMarkdownAssetUuids($markdownBody);
        $markdownIndex = 0;

        return (string) preg_replace_callback(
            '#<img([^>]*)\ssrc=(["\'])([^"\']+)\2([^>]*)>#i',
            function (array $matches) use ($issue, $markdownUuids, &$markdownIndex): string {
                $src = html_entity_decode($matches[3], ENT_QUOTES | ENT_HTML5);
                $uuid = $this->resolveAssetUuid($src, $markdownUuids, $markdownIndex);

                if ($uuid === null) {
                    return $matches[0];
                }

                if (! $this->downloadAndStore($issue, $uuid, $src)) {
                    return $matches[0];
                }

                $localUrl = route('issues.media', [
                    'issue' => $issue,
                    'asset' => $uuid,
                ]);

                return '<img'.$matches[1].' src="'.e($localUrl).'"'.$matches[4].'>';
            },
            $html,
        );
    }

    public function storedMediaPath(Issue $issue, string $assetUuid): ?string
    {
        $directory = "issue-media/{$issue->id}";

        foreach (Storage::disk('local')->files($directory) as $path) {
            if (Str::startsWith(basename($path), $assetUuid.'.')) {
                return $path;
            }
        }

        return null;
    }

    public function storedMediaMimeType(string $path): string
    {
        return match (Str::afterLast($path, '.')) {
            'png' => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            'svg' => 'image/svg+xml',
            default => 'application/octet-stream',
        };
    }

    /**
     * @return array<int, string>
     */
    protected function extractMarkdownAssetUuids(?string $markdownBody): array
    {
        if ($markdownBody === null || trim($markdownBody) === '') {
            return [];
        }

        preg_match_all(
            '#user-attachments/assets/([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})#i',
            $markdownBody,
            $matches,
        );

        return array_values($matches[1] ?? []);
    }

    /**
     * @param  array<int, string>  $markdownUuids
     */
    protected function resolveAssetUuid(string $src, array $markdownUuids, int &$markdownIndex): ?string
    {
        if (preg_match('#user-attachments/assets/([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})#i', $src, $matches) === 1) {
            return strtolower($matches[1]);
        }

        if (preg_match('#([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})#i', $src, $matches) === 1) {
            return strtolower($matches[1]);
        }

        if (! isset($markdownUuids[$markdownIndex])) {
            return null;
        }

        return strtolower($markdownUuids[$markdownIndex++]);
    }

    protected function downloadAndStore(Issue $issue, string $uuid, string $src): bool
    {
        $existing = $this->storedMediaPath($issue, $uuid);

        if ($existing !== null) {
            return true;
        }

        try {
            $response = Http::timeout(15)
                ->connectTimeout(3)
                ->get($src);

            if (! $response->successful()) {
                return false;
            }

            $extension = $this->extensionFromResponse($response->header('Content-Type'), $src);
            $path = "issue-media/{$issue->id}/{$uuid}.{$extension}";

            Storage::disk('local')->put($path, $response->body());

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    protected function extensionFromResponse(?string $contentType, string $src): string
    {
        $extension = strtolower(pathinfo(parse_url($src, PHP_URL_PATH) ?? '', PATHINFO_EXTENSION));

        if (in_array($extension, ['png', 'jpg', 'jpeg', 'gif', 'webp', 'svg'], true)) {
            return $extension === 'jpeg' ? 'jpg' : $extension;
        }

        return match ($contentType) {
            'image/png' => 'png',
            'image/jpeg', 'image/jpg' => 'jpg',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
            'image/svg+xml' => 'svg',
            default => 'bin',
        };
    }
}
