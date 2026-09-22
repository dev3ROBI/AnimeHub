<?php 
include_once '../includes/header.php';
include_once '../includes/db.php';
?>
<div class="upload-container">

    <!-- Upload Movie -->
    <div class="add-movies">
        <h2>Upload Movie</h2>
        <form action="upload_data.php" method="post">
            <input type="hidden" name="form_type" value="movie">

            <label for="name">Movie Name</label>
            <input type="text" id="name" name="name" required><br><br>

            <label for="imdb_id">IMDb ID</label>
            <input type="text" id="imdb_id" name="imdb_id" required><br><br>

            <label for="video_url">Playable Video URL</label>
            <input type="url" id="video_url" name="video_url" required><br><br>

            <input type="submit" value="Upload Movie">
        </form>
    </div>



    <!-- Upload Show -->
    <div class="add-show">
        <h2>Upload Series</h2>
        <form action="upload_data.php" method="post">

            <input type="hidden" name="form_type" value="show">

            <label for="show_title">Show Title</label>
            <input type="text" id="show_title" name="title" required><br><br>

            <label for="imdb_id">IMDb ID</label>
            <input type="text" id="imdb_id" name="imdb_id" required><br><br>

            <label for="type">Type</label>
            <select id="type" name="type" required>
                <option value="" hidden>Select Type</option>
                <option value="anime">Anime</option>
                <option value="web_series">Web Series</option>
            </select><br><br>


            <input type="submit" value="Add Show">
        </form>
    </div>


    <!-- Upload Season -->
    <div class="add-season">
        <h2>Add Season</h2>
        <form action="upload_data.php" method="post">
            <input type="hidden" name="form_type" value="season">
            
            <label for="show_id">Show ID</label>
            <input type="number" id="show_id" name="show_id" required><br><br>

            <label for="season_number">Season Number</label>
            <input type="number" id="season_number" name="season_number" required><br><br>

            <label for="season_title">Season Title</label>
            <input type="text" id="season_title" name="title"><br><br>

            <input type="submit" value="Add Season">
        </form>
    </div>



    <!-- Upload Episode -->
    <div class="add-episode">
        <h2>Add Episode</h2>
        <form action="upload_data.php" method="post">

            <input type="hidden" name="form_type" value="episode">

            <label for="season_id">Season ID</label>
            <input type="number" id="season_id" name="season_id" required><br><br>

            <label for="episode_number">Episode Number</label>
            <input type="number" id="episode_number" name="episode_number" required><br><br>

            <label for="video_url">Video URL</label>
            <input type="url" id="video_url" name="video_url" required><br><br>


            <input type="submit" value="Add Episode">
        </form>
    </div>

</div>

<?php include_once '../includes/footer.php'; ?>