<?php

namespace PitchDeck;

/**
 * The whole site: four routes and no framework.
 *
 * Deliberately dependency-free, for the same reason the drift engine is
 * local. This runs in the back room of a bar on whatever wifi is going, and
 * every piece of it that could fail separately is a piece that can fail
 * during somebody's set.
 */
final class App
{
    public function __construct(
        private Deck $deck,
        private Store $store,
        private string $root,
    ) {}

    public function handle(string $method, string $path): void
    {
        $path = rtrim(parse_url($path, PHP_URL_PATH) ?: '/', '/') ?: '/';

        if ($method === 'POST' && $path === '/') {
            $this->create();

            return;
        }

        if (preg_match('#^/d/(\d+)\.pptx$#', $path, $m)) {
            $this->download((int) $m[1]);

            return;
        }

        if (preg_match('#^/d/(\d+)$#', $path, $m)) {
            $this->show((int) $m[1]);

            return;
        }

        if (preg_match('#^/i/([0-9a-f]{40})\.jpg$#', $path, $m)) {
            $this->image($m[1]);

            return;
        }

        if ($path === '/') {
            $this->home();

            return;
        }

        http_response_code(404);
        $this->render('home', ['recent' => $this->store->recent(), 'error' => 'No such page.']);
    }

    private function home(): void
    {
        $this->render('home', [
            'recent' => $this->store->recent(),
            'total' => $this->store->count(),
            'error' => null,
        ]);
    }

    private function create(): void
    {
        $suggestion = trim((string) ($_POST['suggestion'] ?? ''));

        if ($suggestion === '') {
            $this->render('home', [
                'recent' => $this->store->recent(),
                'total' => $this->store->count(),
                'error' => 'Give it a word first.',
            ]);

            return;
        }

        // Long input is accepted rather than rejected -- somebody will type a
        // paragraph and the deck should be built from it, not refused.
        $built = $this->deck->build(mb_substr($suggestion, 0, 300));
        $id = $this->store->record($built['suggestion'], $built['word'], $built['seed']);

        // 303 so a refresh of the slideshow does not build a second deck.
        header('Location: /d/'.$id, true, 303);
    }

    private function show(int $id): void
    {
        $row = $this->store->find($id);

        if ($row === null) {
            http_response_code(404);
            $this->render('home', ['recent' => $this->store->recent(), 'total' => $this->store->count(), 'error' => 'That deck is gone.']);

            return;
        }

        $this->render('deck', [
            'id' => $id,
            'deck' => $this->rebuild($row),
            'recent' => $this->store->recent(12),
        ]);
    }

    private function download(int $id): void
    {
        $row = $this->store->find($id);

        if ($row === null) {
            http_response_code(404);
            echo 'No such deck.';

            return;
        }

        $built = $this->rebuild($row);
        $file = tempnam(sys_get_temp_dir(), 'deck').'.pptx';
        (new Pptx($this->deck))->write($built, $file);

        $name = preg_replace('/[^A-Za-z0-9]+/', '-', $built['suggestion']) ?: 'deck';

        header('Content-Type: application/vnd.openxmlformats-officedocument.presentationml.presentation');
        header('Content-Disposition: attachment; filename="'.trim($name, '-').'.pptx"');
        header('Content-Length: '.filesize($file));
        readfile($file);
        unlink($file);
    }

    /**
     * Rebuild a stored deck from its seed.
     *
     * The slides were never saved, only the suggestion and the seed, so this
     * is what makes "previous words" replayable and what makes the downloaded
     * file match what was on the screen.
     */
    private function rebuild(array $row): array
    {
        return $this->deck->build($row['suggestion'], (int) $row['seed']);
    }

    private function image(string $key): void
    {
        $file = $this->root.'/storage/images/'.$key.'.jpg';

        if (! is_file($file)) {
            http_response_code(404);

            return;
        }

        // Cached hard: the file name is the hash of its contents' source, so
        // it never changes meaning.
        header('Content-Type: image/jpeg');
        header('Cache-Control: public, max-age=31536000, immutable');
        header('Content-Length: '.filesize($file));
        readfile($file);
    }

    private function render(string $view, array $data): void
    {
        extract($data);
        require $this->root.'/src/views/'.$view.'.php';
    }

    /** The public URL for a cached image, or null. */
    public static function imageUrl(?array $image): ?string
    {
        if (empty($image['file']) || ! is_file($image['file'])) {
            return null;
        }

        return '/i/'.basename($image['file'], '.jpg').'.jpg';
    }

    public static function e(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    }
}
