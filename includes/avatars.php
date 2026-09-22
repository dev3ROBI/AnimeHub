<?php
/**
 * Anime avatar gallery.
 *
 * Users pick an avatar from this gallery instead of uploading one, so the site
 * never stores user-supplied files. The list is pulled from AniList's character
 * catalogue (the same keyless API the rest of the site uses) and cached for a
 * week; the hardcoded list below is served when AniList is unreachable.
 *
 * Stored values are always resolved back through the gallery, so a tampered
 * `User_Avatar` value can never turn into an arbitrary external URL.
 */

include_once __DIR__ . '/../config/config.php';
include_once __DIR__ . '/http.php';

/** Shown when a user has not picked anything, or picked something invalid. */
function avatar_default() {
    return './assets/images/user_avatar.svg';
}

/**
 * Curated fallback gallery. Images verified against the AniList CDN.
 * Keep the ids in sync with the AniList character ids.
 */
function avatar_fallback_gallery() {
    return [
        ['id' => 'ch127691', 'name' => 'Satoru Gojou',  'from' => 'Jujutsu Kaisen', 'img' => 'https://s4.anilist.co/file/anilistcdn/character/large/b127691-9zqh1xpIubn7.png'],
        ['id' => 'ch45627',  'name' => 'Levi',          'from' => 'Attack on Titan', 'img' => 'https://s4.anilist.co/file/anilistcdn/character/large/b45627-CR68RyZmddGG.png'],
        ['id' => 'ch40',     'name' => 'Monkey D. Luffy','from' => 'ONE PIECE',      'img' => 'https://s4.anilist.co/file/anilistcdn/character/large/b40-MNypXsxSRb1R.png'],
        ['id' => 'ch27',     'name' => 'Killua Zoldyck','from' => 'HUNTER x HUNTER', 'img' => 'https://s4.anilist.co/file/anilistcdn/character/large/b27-Z5O02kQUydpT.jpg'],
        ['id' => 'ch40882',  'name' => 'Eren Yeager',   'from' => 'Attack on Titan', 'img' => 'https://s4.anilist.co/file/anilistcdn/character/large/b40882-dsj7IP943WFF.jpg'],
        ['id' => 'ch62',     'name' => 'Roronoa Zoro',  'from' => 'ONE PIECE',      'img' => 'https://s4.anilist.co/file/anilistcdn/character/large/b62-S7oAeA9WInjV.png'],
        ['id' => 'ch417',    'name' => 'Lelouch',       'from' => 'Code Geass',     'img' => 'https://s4.anilist.co/file/anilistcdn/character/large/b417-gVLmIJu9phcK.png'],
        ['id' => 'ch88572',  'name' => 'Emilia',        'from' => 'Re:Zero',        'img' => 'https://s4.anilist.co/file/anilistcdn/character/large/b88572-IzTwXEHSobRs.jpg'],
        ['id' => 'ch71',     'name' => 'L Lawliet',     'from' => 'DEATH NOTE',     'img' => 'https://s4.anilist.co/file/anilistcdn/character/large/b71-1W4panC53vfs.png'],
        ['id' => 'ch87275',  'name' => 'Ken Kaneki',    'from' => 'Tokyo Ghoul',    'img' => 'https://s4.anilist.co/file/anilistcdn/character/large/b87275-mb13EWZBdbh3.png'],
        ['id' => 'ch40881',  'name' => 'Mikasa Ackerman','from' => 'Attack on Titan','img' => 'https://s4.anilist.co/file/anilistcdn/character/large/b40881-F3gr1PkreDvj.png'],
        ['id' => 'ch422',    'name' => 'Guts',          'from' => 'Berserk',        'img' => 'https://s4.anilist.co/file/anilistcdn/character/large/b422-XTaiTuvRohsV.png'],
        ['id' => 'ch176754', 'name' => 'Frieren',       'from' => 'Frieren',        'img' => 'https://s4.anilist.co/file/anilistcdn/character/large/b176754-PCnpqIOkjhFk.png'],
        ['id' => 'ch126824', 'name' => 'Maomao',        'from' => 'The Apothecary Diaries', 'img' => 'https://s4.anilist.co/file/anilistcdn/character/large/b126824-MqsCncTO1qpv.png'],
        ['id' => 'ch34470',  'name' => 'Kurisu Makise', 'from' => 'Steins;Gate',    'img' => 'https://s4.anilist.co/file/anilistcdn/character/large/b34470-Jw2LXZBL5R8i.png'],
        ['id' => 'ch10138',  'name' => 'Thorfinn',      'from' => 'Vinland Saga',   'img' => 'https://s4.anilist.co/file/anilistcdn/character/large/b10138-zOPrka0ddZOR.png'],
        ['id' => 'ch137080', 'name' => 'Makima',        'from' => 'Chainsaw Man',   'img' => 'https://s4.anilist.co/file/anilistcdn/character/large/b137080-UHcynYNjb5ZU.png'],
        ['id' => 'ch14',     'name' => 'Itachi Uchiha', 'from' => 'NARUTO',         'img' => 'https://s4.anilist.co/file/anilistcdn/character/large/b14-9Kb1E5oel1ke.png'],
        ['id' => 'ch127212', 'name' => 'Yuuji Itadori', 'from' => 'Jujutsu Kaisen', 'img' => 'https://s4.anilist.co/file/anilistcdn/character/large/b127212-FVm2tD0erQ5B.png'],
        ['id' => 'ch35252',  'name' => 'Rintarou Okabe','from' => 'Steins;Gate',    'img' => 'https://s4.anilist.co/file/anilistcdn/character/large/b35252-DY9TW6pusqeh.png'],
        ['id' => 'ch127222', 'name' => 'Mai Sakurajima','from' => 'Bunny Girl Senpai', 'img' => 'https://s4.anilist.co/file/anilistcdn/character/large/b127222-Jh5hhP7vZ7s1.png'],
        ['id' => 'ch130102', 'name' => 'Denji',         'from' => 'Chainsaw Man',   'img' => 'https://s4.anilist.co/file/anilistcdn/character/large/b130102-FO1VHNnEnLlB.png'],
        ['id' => 'ch28',     'name' => 'Kurapika',      'from' => 'HUNTER x HUNTER', 'img' => 'https://s4.anilist.co/file/anilistcdn/character/large/b28-ivA7UGnfE40a.png'],
        ['id' => 'ch89616',  'name' => 'Shigeo Kageyama','from' => 'Mob Psycho 100','img' => 'https://s4.anilist.co/file/anilistcdn/character/large/b89616-dXmdOc7L6SDi.png'],
        ['id' => 'ch137079', 'name' => 'Power',         'from' => 'Chainsaw Man',   'img' => 'https://s4.anilist.co/file/anilistcdn/character/large/b137079-6yLEUYR3bmpr.png'],
        ['id' => 'ch85',     'name' => 'Kakashi Hatake','from' => 'NARUTO',         'img' => 'https://s4.anilist.co/file/anilistcdn/character/large/b85-mkVBh2yjxjmx.png'],
        ['id' => 'ch120649', 'name' => 'Kaguya Shinomiya','from' => 'Kaguya-sama',  'img' => 'https://s4.anilist.co/file/anilistcdn/character/large/b120649-NPaWaIpWy60E.png'],
        ['id' => 'ch90169',  'name' => 'Violet Evergarden','from' => 'Violet Evergarden', 'img' => 'https://s4.anilist.co/file/anilistcdn/character/large/b90169-4wr1Zehnsac8.png'],
        ['id' => 'ch80',     'name' => 'Light Yagami',  'from' => 'DEATH NOTE',     'img' => 'https://s4.anilist.co/file/anilistcdn/character/large/b80-26EhwSsSqQ50.png'],
        ['id' => 'ch127518', 'name' => 'Nezuko Kamado', 'from' => 'Demon Slayer',   'img' => 'https://s4.anilist.co/file/anilistcdn/character/large/b127518-NRlq1CQ1v1ro.png'],
        ['id' => 'ch17',     'name' => 'Naruto Uzumaki','from' => 'NARUTO',         'img' => 'https://s4.anilist.co/file/anilistcdn/character/large/b17-phjcWCkRuIhu.png'],
    ];
}

/**
 * The avatar gallery: AniList's most-favourited characters, cached for a week,
 * falling back to the curated list above.
 */
function avatar_gallery() {
    $producer = function () {
        $query = 'query { Page(page: 1, perPage: 60) { characters(sort: FAVOURITES_DESC) { '
               . 'id name { full } image { large } media(perPage: 1) { nodes { title { romaji } } } } } }';

        $res = api_http('https://graphql.anilist.co', [
            'method'  => 'POST',
            'headers' => ['Content-Type: application/json', 'Accept: application/json'],
            'body'    => json_encode(['query' => $query]),
            'timeout' => 12,
        ]);

        if (!is_array($res) || empty($res['data']['Page']['characters'])) return null;

        $out = [];
        foreach ($res['data']['Page']['characters'] as $c) {
            $img = $c['image']['large'] ?? '';
            $name = trim((string)($c['name']['full'] ?? ''));
            if ($img === '' || $name === '' || empty($c['id'])) continue;

            $from = '';
            if (!empty($c['media']['nodes'][0]['title'])) {
                $t = $c['media']['nodes'][0]['title'];
                $from = (string)($t['romaji'] ?? ($t['english'] ?? ''));
            }

            $out[] = [
                'id'   => 'ch' . (int)$c['id'],
                'name' => $name,
                'from' => $from,
                'img'  => $img,
            ];
        }

        return $out ?: null;
    };

    // Key carries the page size: bump it when the requested page changes so an
    // older, smaller cached page is not reused.
    $live = api_cache_remember('characters.top.60', 'anilist', 604800, $producer);

    /**
     * Filter: let a site owner extend the gallery without editing this file.
     */
    $extra = [];
    if (function_exists('kp_avatar_extra')) {
        $extra = (array)kp_avatar_extra();
    }

    /*
     * The curated list is always part of the gallery, never just a fallback.
     * It is what keeps a previously saved avatar resolvable when AniList drops
     * a character out of its top page — otherwise a stored id would silently
     * collapse to the default picture, and picking one of these would be
     * rejected as "not in the gallery".
     */
    $gallery = [];
    $seen = [];
    $sources = array_merge(
        [(array)$extra],
        [avatar_fallback_gallery()],
        [is_array($live) ? $live : []]
    );

    foreach ($sources as $source) {
        foreach ($source as $avatar) {
            $id = (string)($avatar['id'] ?? '');
            if ($id === '' || isset($seen[$id])) continue;
            $seen[$id] = true;
            $gallery[] = $avatar;
        }
    }

    return $gallery;
}

/** Index the gallery by avatar id for O(1) validation. */
function avatar_gallery_index() {
    static $index = null;
    if ($index !== null) return $index;

    $index = [];
    foreach (avatar_gallery() as $avatar) {
        if (!empty($avatar['id'])) $index[(string)$avatar['id']] = $avatar;
    }
    return $index;
}

/**
 * Turn a stored value into a displayable, trusted image URL.
 *
 * Anything not in the gallery (including a hand-edited value) collapses to the
 * default avatar rather than being rendered.
 */
function avatar_resolve($value) {
    $value = trim((string)$value);
    if ($value === '') return avatar_default();

    $index = avatar_gallery_index();
    if (isset($index[$value])) return $index[$value]['img'];

    // Tolerate a raw image URL that still matches a gallery entry, so avatars
    // saved by an earlier build keep working.
    foreach ($index as $avatar) {
        if ($avatar['img'] === $value) return $avatar['img'];
    }

    return avatar_default();
}

/** Metadata (character name / series) for the avatar a user picked. */
function avatar_meta($value) {
    $value = trim((string)$value);
    if ($value === '') return null;

    $index = avatar_gallery_index();
    if (isset($index[$value])) return $index[$value];

    foreach ($index as $avatar) {
        if ($avatar['img'] === $value) return $avatar;
    }
    return null;
}

/** True when the value is something a user is allowed to save. */
function avatar_is_valid($value) {
    $value = trim((string)$value);
    return $value !== '' && avatar_meta($value) !== null;
}

/**
 * Stored avatar value for a user, or '' when unset / unavailable.
 *
 * Called from the navbar, so it must not assume db.php has been included yet.
 */
function user_avatar_value($user_id) {
    if (!$user_id) return '';

    global $conn;
    if (!$conn) {
        include_once __DIR__ . '/db.php';
    }
    if (!$conn) return '';

    try {
        $stmt = $conn->prepare("SELECT User_Avatar FROM users WHERE User_ID = ?");
        if (!$stmt) return ''; // column missing until migration 004 runs
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        return (string)($row['User_Avatar'] ?? '');
    } catch (Throwable $e) {
        return '';
    }
}

/** Ready-to-use avatar image URL for a user. */
function user_avatar_url($user_id) {
    return avatar_resolve(user_avatar_value($user_id));
}
