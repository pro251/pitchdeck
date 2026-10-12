<?php

namespace PitchDeck;

use PDO;

/**
 * Every suggestion the audience has given.
 *
 * Only the suggestion and the seed are stored, never the built slides. The
 * deck is a pure function of those two, so keeping the output as well would
 * be two records of the same fact -- and the one nobody writes to is the one
 * that gets believed. Replaying a word rebuilds it identically.
 */
final class Store
{
    public function __construct(private PDO $db)
    {
        $this->db->exec('CREATE TABLE IF NOT EXISTS deck (
            id INTEGER PRIMARY KEY,
            suggestion TEXT NOT NULL,
            word TEXT NOT NULL,
            seed INTEGER NOT NULL,
            created_at INTEGER NOT NULL
        )');
        $this->db->exec('CREATE INDEX IF NOT EXISTS idx_deck_created ON deck (created_at DESC)');
    }

    public static function open(string $path): self
    {
        $db = new PDO("sqlite:$path", options: [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);

        // A show is one laptop and one browser, but the cache warmer runs on
        // cron and will be writing at the same time.
        $db->exec('PRAGMA journal_mode = WAL');
        $db->exec('PRAGMA busy_timeout = 3000');

        return new self($db);
    }

    public function record(string $suggestion, string $word, int $seed): int
    {
        $q = $this->db->prepare(
            'INSERT INTO deck (suggestion, word, seed, created_at) VALUES (?, ?, ?, ?)'
        );
        $q->execute([$suggestion, $word, $seed, time()]);

        return (int) $this->db->lastInsertId();
    }

    public function find(int $id): ?array
    {
        $q = $this->db->prepare('SELECT * FROM deck WHERE id = ?');
        $q->execute([$id]);

        return $q->fetch() ?: null;
    }

    /** @return array<int, array> newest first */
    public function recent(int $limit = 40): array
    {
        $q = $this->db->prepare('SELECT * FROM deck ORDER BY created_at DESC, id DESC LIMIT ?');
        $q->execute([$limit]);

        return $q->fetchAll();
    }

    public function count(): int
    {
        return (int) $this->db->query('SELECT count(*) FROM deck')->fetchColumn();
    }
}
