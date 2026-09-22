<?php
/**
 * Trending list for the profile / sidebar widgets.
 * Returns [{title, poster, imdb_rating, imdb_id, total_views, source}, ...]
 */
session_start();

include_once __DIR__ . '/db.php';
include_once __DIR__ . '/functions.php';

header('Content-Type: application/json');

$trending = [];

// ─── Catalogue providers ──────────────────────────────────────────────
try {
    $items = catalog_trending(20);
    $rank = 0;
    foreach ($items as $item) {
        if (empty($item['id'])) continue;
        $rank++;
        $trending[] = [
            'title'       => $item['title'] ?? 'Unknown',
            'poster'      => $item['poster'] ?? './uploads/thumbnails/default.png',
            'imdb_rating' => $item['score'] ?? ($item['rating'] ?? 'N/A'),
            'imdb_id'     => $item['id'],
            'total_views' => max(0, 100000 - ($rank * 5000)),
            'source'      => $item['provider'] ?? null,
        ];
    }
} catch (Exception $e) {
    error_log('Trending provider fetch failed: ' . $e->getMessage());
}

// ─── Fallback: locally stored items ranked by recorded views ─────────
if (empty($trending)) {
    try {
        $sql = "
            SELECT v.imdb_id, COUNT(v.imdb_id) AS total_views
            FROM views v
            GROUP BY v.imdb_id
            ORDER BY total_views DESC
            LIMIT 10
        ";
        $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
        $ids = array_slice(array_column($rows, 'imdb_id'), 0, 10);

        if ($ids) {
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $movies = $pdo->prepare("SELECT imdb_id, name AS title, imdb_poster AS poster, imdb_rating FROM movies WHERE imdb_id IN ($placeholders)");
            $movies->execute($ids);
            $shows = $pdo->prepare("SELECT imdb_id, title, imdb_poster AS poster, imdb_rating FROM shows WHERE imdb_id IN ($placeholders)");
            $shows->execute($ids);

            $views = array_column($rows, 'total_views', 'imdb_id');
            foreach (array_merge($movies->fetchAll(PDO::FETCH_ASSOC), $shows->fetchAll(PDO::FETCH_ASSOC)) as $row) {
                $row['total_views'] = (int)($views[$row['imdb_id']] ?? 0);
                $row['poster'] = $row['poster'] ?: './uploads/thumbnails/default.png';
                $row['source'] = 'local';
                $trending[] = $row;
            }
            usort($trending, fn($a, $b) => ($b['total_views'] ?? 0) <=> ($a['total_views'] ?? 0));
        }
    } catch (Exception $e) {
        error_log('Trending local fallback failed: ' . $e->getMessage());
    }
}

if (empty($trending)) {
    echo json_encode(['message' => 'No trending content available']);
    exit;
}

echo json_encode($trending, JSON_UNESCAPED_UNICODE);
