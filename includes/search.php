<?php
/**
 * Typeahead search. Returns a flat list of
 *   { title, type, imdb_id, source, poster, year }
 * where imdb_id holds the provider id (e.g. "anilist:21") so watch.php
 * knows which provider to use.
 */
include_once __DIR__ . '/db.php';
include_once __DIR__ . '/functions.php';

header('Content-Type: application/json');

$term = trim($_GET['term'] ?? '');
$limit = isset($_GET['limit']) ? max(1, min(30, intval($_GET['limit']))) : 20;

if (mb_strlen($term) < 2) {
    echo json_encode([]);
    exit;
}

$results = [];

// ─── Catalogue providers (AniList -> ReAnime -> Jikan) ────────────────
try {
    foreach (catalog_search($term, $limit) as $item) {
        if (empty($item['id'])) continue;
        $results[] = [
            'title'   => $item['title'] ?? 'Unknown',
            'type'    => 'show',
            'imdb_id' => $item['id'],
            'source'  => $item['provider'] ?? 'catalog',
            'poster'  => $item['poster'] ?? '',
            'year'    => $item['year'] ?? null,
            'rating'  => $item['score'] ?? null,
        ];
    }
} catch (Exception $e) {
    error_log('Catalogue search failed: ' . $e->getMessage());
}

// ─── Locally stored movies / shows ───────────────────────────────────
$limitRemaining = max(0, $limit - count($results));
if ($limitRemaining > 0) {
    global $pdo;
    $searchLike = "%$term%";

    try {
        $stmtMovies = $pdo->prepare("SELECT name AS title, category AS type, imdb_id, imdb_poster AS poster, release_date FROM movies WHERE name LIKE ? LIMIT ?");
        $stmtMovies->bindValue(1, $searchLike, PDO::PARAM_STR);
        $stmtMovies->bindValue(2, $limitRemaining, PDO::PARAM_INT);
        $stmtMovies->execute();
        $movies = $stmtMovies->fetchAll(PDO::FETCH_ASSOC);

        $stmtShows = $pdo->prepare("SELECT title, type, imdb_id, imdb_poster AS poster, release_date FROM shows WHERE title LIKE ? LIMIT ?");
        $stmtShows->bindValue(1, $searchLike, PDO::PARAM_STR);
        $stmtShows->bindValue(2, $limitRemaining, PDO::PARAM_INT);
        $stmtShows->execute();
        $shows = $stmtShows->fetchAll(PDO::FETCH_ASSOC);

        $seen = array_column($results, 'imdb_id');
        foreach (array_merge($movies, $shows) as $item) {
            if (empty($item['imdb_id']) || in_array($item['imdb_id'], $seen, true)) continue;
            $item['source'] = (($item['type'] ?? '') === 'movie') ? 'movie' : 'show';
            $item['type'] = $item['source'];
            if (!empty($item['release_date'])) $item['year'] = (int)substr($item['release_date'], 0, 4);
            $results[] = $item;
            $seen[] = $item['imdb_id'];
        }
    } catch (Exception $e) {
        error_log("Local search failed: " . $e->getMessage());
    }
}

echo json_encode(array_slice($results, 0, $limit), JSON_UNESCAPED_UNICODE);
