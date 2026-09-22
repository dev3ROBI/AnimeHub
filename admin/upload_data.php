<?php
include_once '../includes/db.php';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {

    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

    // Upload Movie
    if (isset($_POST['form_type']) && $_POST['form_type'] === 'movie') {
        $name = $_POST['name'];
        $category = 'movie';
        $imdb_id = $_POST['imdb_id'];
        $video_url = $_POST['video_url'];

        $api_key = 'd44a4778';
        $api_url = "http://www.omdbapi.com/?i=$imdb_id&apikey=$api_key";
        $json = file_get_contents($api_url);
        $data = json_decode($json, true);

        $imdb_rating = $data['imdbRating'] ?? null;
        $imdb_poster = ($data['Poster'] !== "N/A") ? $data['Poster'] : null;
        $plot = $data['Plot'] ?? null;
        $genre = $data['Genre'] ?? null;
        $release_date = $data['Released'] ?? null;
        $runtime = $data['Runtime'] ?? null;
        $actors = $data['Actors'] ?? null;
        $director = $data['Director'] ?? null;
        $writer = $data['Writer'] ?? null;
        $language = $data['Language'] ?? null;
        $country = $data['Country'] ?? null;

        $sql = "INSERT INTO movies (
            name, category, imdb_id, video_url, imdb_rating, imdb_poster,
            plot, genre, release_date, runtime, actors, director, writer, language, country
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";

        $stmt = $conn->prepare($sql);
        $stmt->bind_param("sssssssssssssss",
            $name, $category, $imdb_id, $video_url, $imdb_rating, $imdb_poster,
            $plot, $genre, $release_date, $runtime, $actors, $director, $writer, $language, $country
        );

        $stmt->execute();
        echo "✅ Movie added successfully!";
    }

    // Upload Show
    elseif (isset($_POST['form_type']) && $_POST['form_type'] === 'show') {
        $title = $_POST['title'];
        $type = $_POST['type'];
        $imdb_id = $_POST['imdb_id'];

        $api_key = 'd44a4778';
        $api_url = "http://www.omdbapi.com/?i=$imdb_id&apikey=$api_key";
        $json = file_get_contents($api_url);
        $data = json_decode($json, true);

        $imdb_rating = $data['imdbRating'] ?? null;
        $imdb_poster = ($data['Poster'] !== "N/A") ? $data['Poster'] : null;
        $plot = $data['Plot'] ?? null;
        $genre = $data['Genre'] ?? null;
        $release_date = $data['Released'] ?? null;
        $runtime = $data['Runtime'] ?? null;
        $actors = $data['Actors'] ?? null;
        $director = $data['Director'] ?? null;
        $writer = $data['Writer'] ?? null;
        $language = $data['Language'] ?? null;
        $country = $data['Country'] ?? null;

        $sql = "INSERT INTO shows (
            title, type, imdb_id, imdb_rating, imdb_poster,
            plot, genre, release_date, runtime, actors, director, writer, language, country
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";

        $stmt = $conn->prepare($sql);
        $stmt->bind_param("ssssssssssssss",
            $title, $type, $imdb_id, $imdb_rating, $imdb_poster,
            $plot, $genre, $release_date, $runtime, $actors, $director, $writer, $language, $country
        );

        $stmt->execute();
        echo "✅ Series added successfully!";
    }

    // Upload Season
    elseif (isset($_POST['form_type']) && $_POST['form_type'] === 'season') {
        $show_id = $_POST['show_id'];
        $season_number = $_POST['season_number'];
        $season_title = $_POST['title'];

        $sql = "INSERT INTO seasons (show_id, season_number, season_title) VALUES (?, ?, ?)";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("iis", $show_id, $season_number, $season_title);

        $stmt->execute();
        echo "✅ Season added successfully!";
    }

    // Upload Episode
    elseif (isset($_POST['form_type']) && $_POST['form_type'] === 'episode') {
        $season_id = $_POST['season_id'];
        $episode_number = $_POST['episode_number'];
        $video_url = $_POST['video_url'];

        $sql = "INSERT INTO episodes (season_id, episode_number, video_url) VALUES (?, ?, ?)";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("iis", $season_id, $episode_number, $video_url);

        $stmt->execute();
        echo "✅ Episode added successfully!";
    }

    else {
        echo "❌ Missing or invalid form data.";
    }
} else {
    echo "❌ Invalid request method.";
}
?>
