<?php
include_once './db.php';

// Ensure the season_id is provided and is a valid integer
if (isset($_GET['season_id'])) {
    $season_id = intval($_GET['season_id']);  // sanitize input

    // Prepare the query to fetch episodes for the given season
    $sql = "SELECT id, video_url, episode_number FROM episodes WHERE season_id = ? ORDER BY episode_number ASC";
    $stmt = $conn->prepare($sql);

    if ($stmt === false) {
        echo json_encode(['error' => 'Failed to prepare the query.']);
        exit;
    }

    $stmt->bind_param("i", $season_id);
    $stmt->execute();

    // Check if the query was successful
    if ($stmt->error) {
        echo json_encode(['error' => 'Database query failed: ' . $stmt->error]);
        exit;
    }

    $result = $stmt->get_result();

    // Check if any episodes are found
    if ($result->num_rows > 0) {
        $episodes = [];
        $is_https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');
        $scheme = $is_https ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $path = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/', 2), '/');
        $base_url = $scheme . '://' . $host . $path;

        while ($row = $result->fetch_assoc()) {
            $full_url = (strpos($row['video_url'], 'http') === 0)
                ? $row['video_url']
                : $base_url . '/' . ltrim($row['video_url'], '/');

            $episodes[] = [
                'episode_number' => $row['episode_number'],
                'video_url' => $full_url,
                'video_id' => $row['id'],
            ];
        }

        // Return the episodes as a JSON response
        header('Content-Type: application/json');
        echo json_encode($episodes);
    } else {
        echo json_encode([]);  // No episodes found
    }

    exit;
}

// If no season_id is provided, return an empty response
echo json_encode([]);

