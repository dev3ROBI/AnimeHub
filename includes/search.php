<?php
/**
 * Typeahead search across the whole catalogue.
 *
 *   term          query text (required, >= 2 chars)
 *   kind          all | anime | movie | tv   (default all)
 *
 * Returns a flat list of
 *   { title, kind, imdb_id, source, poster, year, rating, episodes, status }
 * where imdb_id holds the provider id (e.g. "anilist:21", "tmdb:movie:12")
 * so watch.php knows which provider to use. `kind` is what the UI filter
 * chips match against: anime (AniList/Jikan/ReAnime/Anikuro), movie (TMDB +
 * local), tv (TMDB TV + local shows).
 */
include_once __DIR__ . '/db.php';
include_once __DIR__ . '/functions.php';

header('Content-Type: application/json; charset=utf-8');

$term  = trim($_GET['term'] ?? '');
$kind  = strtolower(trim($_GET['kind'] ?? 'all'));
if (!in_array($kind, ['all', 'anime', 'movie', 'tv'], true)) $kind = 'all';
$limit = isset($_GET['limit']) ? max(1, min(30, intval($_GET['limit']))) : 20;

if (mb_strlen($term) < 2) {
    echo json_encode([]);
    exit;
}

/** Classify a catalogue item into the UI's four kinds. */
function search_kind_of(array $item): string {
    $ct = $item['content_type'] ?? '';
    if ($ct === 'movie') return 'movie';
    if ($ct === 'tv') return 'tv';
    $provider = $item['provider'] ?? '';
    if ($provider === 'tmdb') {
        $id = (string)($item['id'] ?? '');
        return strpos($id, 'tmdb:movie:') === 0 ? 'movie' : 'tv';
    }
    $format = strtolower((string)($item['format'] ?? $item['type'] ?? ''));
    if ($format === 'movie' || $format === 'film') return 'movie';
    return 'anime';
}

$results = [];

// ─── Catalogue providers (AniList -> ReAnime -> Jikan -> Anikuro -> TMDB) ─
try {
    foreach (catalog_search($term, $limit) as $item) {
        if (empty($item['id'])) continue;
        $k = search_kind_of($item);
        if ($kind !== 'all' && $k !== $kind) continue;

        $status = (string)($item['status'] ?? '');
        // Provider-independent display status for the row badge.
        $display = 'Released';
        if ($status === 'RELEASING' || $status === 'CURRENTLY_AIRING' || $status === 'RETURNING SERIES') $display = 'Airing';
        elseif ($status === 'NOT_YET_RELEASED' || $status === 'UPCOMING') $display = 'Upcoming';
        elseif ($status === 'FINISHED' || $status === 'ENDED' || $status === 'COMPLETE' || $status === 'RELEASED') $display = 'Released';

        $results[] = [
            'title'    => $item['title'] ?? 'Unknown',
            'kind'     => $k,
            'imdb_id'  => $item['id'],
            'source'   => $item['provider'] ?? 'catalog',
            'poster'   => $item['poster'] ?? '',
            'year'     => $item['year'] ?? null,
            'rating'   => ($item['score'] ?? null) !== null ? (string)$item['score'] : null,
            'episodes' => (int)(($item['aired_episodes'] ?? 0) ?: ($item['episodes'] ?? 0)),
            'status'   => $display,
        ];
    }
} catch (Exception $e) {
    error_log('Catalogue search failed: ' . $e->getMessage());
}

// ─── Locally stored movies / shows ────────────────────────────────────
$limitRemaining = max(0, $limit - count($results));
if ($limitRemaining > 0 && in_array($kind, ['all', 'movie', 'tv'], true)) {
    global $pdo;
    $searchLike = "%$term%";

    try {
        $rows = [];
        if ($kind !== 'tv') {
            $stmtMovies = $pdo->prepare("SELECT name AS title, category AS type, imdb_id, imdb_poster AS poster, release_date FROM movies WHERE name LIKE ? LIMIT ?");
            $stmtMovies->bindValue(1, $searchLike, PDO::PARAM_STR);
            $stmtMovies->bindValue(2, $limitRemaining, PDO::PARAM_INT);
            $stmtMovies->execute();
            $rows = array_merge($rows, $stmtMovies->fetchAll(PDO::FETCH_ASSOC));
        }
        if ($kind !== 'movie') {
            $stmtShows = $pdo->prepare("SELECT title, type, imdb_id, imdb_poster AS poster, release_date FROM shows WHERE title LIKE ? LIMIT ?");
            $stmtShows->bindValue(1, $searchLike, PDO::PARAM_STR);
            $stmtShows->bindValue(2, $limitRemaining, PDO::PARAM_INT);
            $stmtShows->execute();
            $rows = array_merge($rows, $stmtShows->fetchAll(PDO::FETCH_ASSOC));
        }

        $seen = array_column($results, 'imdb_id');
        foreach ($rows as $item) {
            if (empty($item['imdb_id']) || in_array($item['imdb_id'], $seen, true)) continue;
            $k = (($item['type'] ?? '') === 'movie') ? 'movie' : 'tv';
            if ($kind !== 'all' && $k !== $kind) continue;
            $year = null;
            if (!empty($item['release_date'])) $year = (int)substr((string)$item['release_date'], 0, 4);
            $results[] = [
                'title'    => $item['title'] ?? 'Unknown',
                'kind'     => $k,
                'imdb_id'  => $item['imdb_id'],
                'source'   => $k,
                'poster'   => $item['poster'] ?? '',
                'year'     => $year,
                'rating'   => null,
                'episodes' => 0,
                'status'   => 'Released',
            ];
            $seen[] = $item['imdb_id'];
        }
    } catch (Exception $e) {
        error_log("Local search failed: " . $e->getMessage());
    }
}

echo json_encode(array_slice($results, 0, $limit), JSON_UNESCAPED_UNICODE);
