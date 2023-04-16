<?php

namespace App\Http\Controllers;

use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller as BaseController;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use OpenAI\Laravel\Facades\OpenAI;
use Soundasleep\Html2Text;
use Throwable;

/**
 * @phpstan-type TReply array{
 *     main: string,
 *     comment: string,
 * }
 * @phpstan-type TChapter array{
 *     time: string,
 *     summarize: string,
 * }
 */
class Controller extends BaseController
{
    protected string $userAgent = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10.15; rv:102.0) Gecko/20100101 Firefox/102.0';

    /**
     * Video whitelist.
     *
     * @var array<string, array<int, string>>
     */
    protected array $whitelist = [
        'youtube' => [
            'www.youtube.com',
            'youtu.be',
            'm.youtube.com',
            'www.youtube-nocookie.com',
        ],
    ];

    protected CarbonImmutable $ttl;

    public function __construct()
    {
        $this->ttl = now()->addHour()->toImmutable();
    }

    /**
     * Summarize a webpage or video.
     */
    public function __invoke(Request $request): JsonResponse
    {
        $url = $request->query('url', '/');

        if (! is_string($url)) {
            return $this->error();
        }

        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            return $this->error();
        }

        $host = parse_url($url, PHP_URL_HOST);

        if (! $host) {
            return $this->error();
        }

        if (in_array($host, Arr::flatten($this->whitelist), true)) {
            $path = $this->video($url);

            if (! $path) {
                return $this->error();
            }

            try {
                $chapters = $this->whisper($path);
            } catch (Throwable) {
                return $this->error();
            }

            $replies = array_map(fn (array $chapter) => sprintf('%s - %s', $chapter['time'], $chapter['summarize']), $chapters);

            return new JsonResponse([
                'ok' => true,
                'url' => $url,
                'reply' => [
                    'main' => implode(PHP_EOL, $replies),
                    'comment' => null,
                ],
            ]);
        }

        try {
            $reply = $this->chat($url, $this->scrape($url, $host));
        } catch (Throwable) {
            return $this->error();
        }

        return new JsonResponse([
            'ok' => true,
            'url' => $url,
            'reply' => $reply,
        ]);
    }

    /**
     * Download the video and extract the audio.
     */
    protected function video(string $url): string|null
    {
        $key = sprintf('audio-%s', md5($url));

        $cached = Cache::get($key);

        if (is_string($cached) && ! empty($cached)) {
            return $cached;
        }

        $path = sprintf('%s/%s', storage_path('temp'), Str::uuid());

        $audio = sprintf('%s.webm', $path);

        $working = dirname($path);

        $filename = basename($path);

        $successful = Process::path($working)
            ->timeout(10)
            ->quietly()
            ->run([
                'yt-dlp',
                '--abort-on-error',
                '--no-playlist',
                '--no-part',
                '--format',
                '140',
                '--user-agent',
                $this->userAgent,
                '--output',
                $filename,
                $url,
            ])
            ->successful();

        if (! $successful) {
            @unlink($path);

            return null;
        }

        $ok = Process::path($working)
            ->timeout(10)
            ->quietly()
            ->run([
                'ffmpeg',
                '-i',
                $filename,
                '-t',
                '00:15:00',
                '-vn',
                '-c:a',
                'libopus',
                '-b:a',
                '64k',
                $audio,
            ])
            ->successful();

        @unlink($path);

        if (! $ok) {
            @unlink($audio);

            return null;
        }

        Cache::put($key, $audio);

        return $audio;
    }

    /**
     * Crawl a webpage and convert the HTML to plaintext.
     */
    protected function scrape(string $url, string $host): string
    {
        $key = sprintf('scrape-%s', md5($url));

        $cached = Cache::get($key);

        if (is_string($cached) && ! empty($cached)) {
            return $cached;
        }

        $html = Http::withUserAgent($this->userAgent)
            ->withCookies(['over18' => '1'], $host)
            ->get($url)
            ->body();

        $text = Html2Text::convert($html, [
            'ignore_errors' => true,
            'drop_links' => true,
        ]);

        return tap(
            $text,
            fn ($data) => Cache::put($key, $data, $this->ttl),
        );
    }

    /**
     * Summarize a webpage as content and comment with OpenAI GPT API.
     *
     * @return TReply
     */
    protected function chat(string $url, string $text): array
    {
        $key = sprintf('webpage-%s', md5($url));

        /** @var TReply|null $cached */
        $cached = Cache::get($key);

        if (! empty($cached)) {
            return $cached;
        }

        $context = Str::limit($text, 5000, '');

        $response = OpenAI::chat()->create([
            'model' => 'gpt-3.5-turbo',
            'messages' => [
                [
                    'role' => 'system',
                    'content' => '你是一位輿情分析人員，將協助使用者掌握網路動態，並以 JSON 格式回傳你的分析給使用者，你的分析必須以繁體中文呈現',
                ],
                [
                    'role' => 'user',
                    'content' => <<<EOF
你的回答必須且僅能使用以下 JSON 格式
`{"main":"","comment":""}`

請針對上方格式中的 key 做不同的事情
- `main`: 以 50 到 150 個字統整主文大綱
- `comment`: 請分析文章評論或留言，如果沒有，請使用空字串

---

{$context}
EOF,
                ],
            ],
        ]);

        /** @var TReply $data */
        $data = json_decode(
            $response->choices[array_key_last($response->choices)]->message->content,
            true,
        );

        if ($data !== null) {
            Cache::put($key, $data, $this->ttl);
        }

        return $data;
    }

    /**
     * Summarize an audio with OpenAI Whisper and GPT API.
     *
     * @return TChapter[]
     */
    protected function whisper(string $path): array
    {
        $srt = sprintf('%s.srt', Str::beforeLast($path, '.'));

        if (File::exists($srt)) {
            $text = File::get($srt);
        } else {
            $response = OpenAI::audio()->transcribe([
                'model' => 'whisper-1',
                'file' => fopen($path, 'r'),
                'response_format' => 'srt',
            ]);

            $text = $response->text;

            File::put($srt, $text);
        }

        $key = sprintf('video-%s', md5($srt));

        /** @var TChapter[]|null $cached */
        $cached = Cache::get($key);

        if (! empty($cached)) {
            return $cached;
        }

        $text = Str::of($text)
            ->replaceMatches('/(( --> .+)|(\d+))$/im', '')
            ->limit(5000, '')
            ->toString();

        $response = OpenAI::chat()->create([
            'model' => 'gpt-3.5-turbo',
            'messages' => [
                [
                    'role' => 'system',
                    'content' => '你是一位影片解說人員，將協助使用者快速了解一部影片，並以 JSON 格式回傳你的分析給使用者',
                ],
                [
                    'role' => 'user',
                    'content' => <<<EOF
你的回答必須且僅能使用以下 JSON 格式
`[{"time":"","summarize":""}]`

請針對上方格式中的 key 做不同的事情
- `time`: 該段落的時間開始點
- `summarize`: 以 25 到 50 個字統整該段落大意，須用繁體中文

拆分影片時，最多只能拆成五個段落

---

{$text}
EOF,
                ],
            ],
        ]);

        /** @var TChapter[] $data */
        $data = json_decode(
            $response->choices[array_key_last($response->choices)]->message->content,
            true,
        );

        if ($data !== null) {
            Cache::put($key, $data, $this->ttl);
        }

        return $data;
    }

    protected function error(): JsonResponse
    {
        return new JsonResponse(['ok' => false]);
    }
}
