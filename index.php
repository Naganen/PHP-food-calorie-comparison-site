<?php
/**
 * CaloComTr — Recipe Calorie Comparison Engine
 * Single-file PHP 8.x + SQLite (PDO).
 *
 * DATA MODEL: every row is a REAL recipe published on a real site.
 *   source_url = the actual recipe page, publisher = the real domain,
 *   calories   = the site's own figure when it publishes one ('published'),
 *                otherwise computed from that page's real ingredients ('estimated').
 *
 * DISCOVERY: no web search engine. We only ever query sites we already know
 * work, using their own public search API (WordPress REST) or their public
 * sitemap. A site that repeatedly returns nothing is disabled automatically,
 * so searches stay fast and every result is a valid recipe page.
 * New sites join the pool through the "Kaynak ekle" panel.
 */

declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '0');
mb_internal_encoding('UTF-8');
date_default_timezone_set('Europe/Istanbul');

define('DB_FILE', __DIR__ . '/recipes_cache.sqlite');
define('HTTP_TIMEOUT', 8);
define('UA', 'CaloComTr/3.0 (recipe calorie comparison; respects robots.txt)');
define('MAX_IMPORT_PER_REQUEST', 12);
define('DISCOVER_LIMIT', 6);         // recipes imported per discovery run
define('DISCOVER_TTL_DAYS', 7);      // don't re-search the same dish for this long
define('MIN_SOURCES_PER_DISH', 3);   // discovery kicks in below this many sites
define('SITE_FAIL_LIMIT', 3);        // consecutive empty runs before a site is disabled
define('SITEMAP_URL_CAP', 2500);     // URLs cached per site
define('AUTO_TOPUP_TARGET', 40);     // pull from TheMealDB until the pool reaches this

/** Sites the pool starts with. Everything else is added from the UI. */
function seed_sites(): array
{
    return [
        'yemek.com',
        'kevserinmutfagi.com',
        'pratikyemektarifleri.com',
        'bbcgoodfood.com',
        'allrecipes.com',
        'seriouseats.com',
        'simplyrecipes.com',
        'budgetbytes.com',
    ];
}

/* ================================================================== */
/* Helpers                                                            */
/* ================================================================== */

function h(mixed $v): string
{
    return htmlspecialchars(is_scalar($v) ? (string) $v : '', ENT_QUOTES, 'UTF-8');
}

function norm(mixed $v): string
{
    if (is_array($v)) {
        $v = implode(' ', array_map('norm', $v));
    }
    if (!is_scalar($v)) {
        return '';
    }
    $s = str_replace(['İ', 'I', 'Ş', 'Ğ', 'Ü', 'Ö', 'Ç'], ['i', 'ı', 'ş', 'ğ', 'ü', 'ö', 'ç'], (string) $v);
    return trim(preg_replace('/\s+/u', ' ', mb_strtolower($s, 'UTF-8')) ?? '');
}

/** ASCII slug — Turkish letters transliterated so URLs and dish names match. */
function slugify(mixed $v): string
{
    $s = strtr(norm($v), ['ı' => 'i', 'ş' => 's', 'ğ' => 'g', 'ü' => 'u', 'ö' => 'o', 'ç' => 'c']);
    return trim(preg_replace('/[^a-z0-9]+/', '-', $s) ?? '', '-');
}

function to_list(mixed $v): array
{
    if ($v === null) {
        return [];
    }
    if (is_string($v)) {
        $t = trim($v);
        if ($t === '') {
            return [];
        }
        if ($t[0] === '[' || $t[0] === '{') {
            $d = json_decode($t, true);
            if (is_array($d)) {
                return to_list($d);
            }
        }
        return [$t];
    }
    if (is_array($v)) {
        $out = [];
        foreach ($v as $item) {
            foreach (to_list($item) as $flat) {
                $out[] = $flat;
            }
        }
        return array_values(array_unique($out));
    }
    if (is_scalar($v)) {
        $s = trim((string) $v);
        return $s === '' ? [] : [$s];
    }
    return [];
}

function csv_to_list(mixed $raw): array
{
    $parts = [];
    foreach (to_list($raw) as $chunk) {
        foreach (explode(',', $chunk) as $p) {
            $n = norm($p);
            if ($n !== '') {
                $parts[] = $n;
            }
        }
    }
    return array_values(array_unique($parts));
}

function domain_of(mixed $url): string
{
    $host = is_string($url) ? parse_url($url, PHP_URL_HOST) : null;
    return is_string($host) ? (preg_replace('/^www\./', '', $host) ?? $host) : '';
}

function abs_url(string $href, string $base): string
{
    if ($href === '' || preg_match('#^https?://#i', $href)) {
        return $href;
    }
    $p = parse_url($base);
    if (!isset($p['scheme'], $p['host'])) {
        return '';
    }
    if (str_starts_with($href, '//')) {
        return $p['scheme'] . ':' . $href;
    }
    if (str_starts_with($href, '/')) {
        return $p['scheme'] . '://' . $p['host'] . $href;
    }
    $dir = rtrim(dirname($p['path'] ?? '/'), '/');
    return $p['scheme'] . '://' . $p['host'] . $dir . '/' . $href;
}

/* ================================================================== */
/* HTTP + robots.txt                                                  */
/* ================================================================== */

function http_get(string $url, int $timeout = HTTP_TIMEOUT): ?string
{
    if (!preg_match('#^https?://#i', $url)) {
        return null;
    }
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 4,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_USERAGENT => UA,
            CURLOPT_ENCODING => '',
            CURLOPT_HTTPHEADER => ['Accept-Language: tr,en;q=0.8'],
        ]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return (is_string($body) && $body !== '' && $code >= 200 && $code < 400) ? $body : null;
    }
    $ctx = stream_context_create(['http' => ['timeout' => $timeout, 'user_agent' => UA]]);
    $r = @file_get_contents($url, false, $ctx);
    return (is_string($r) && $r !== '') ? $r : null;
}

function http_json(string $url): ?array
{
    $b = http_get($url);
    if ($b === null) {
        return null;
    }
    $d = json_decode($b, true);
    return is_array($d) ? $d : null;
}

function robots_allows(string $url): bool
{
    static $rules = [];
    $host = domain_of($url);
    if ($host === '') {
        return false;
    }
    if (!array_key_exists($host, $rules)) {
        $scheme = parse_url($url, PHP_URL_SCHEME) ?: 'https';
        $txt = http_get($scheme . '://' . $host . '/robots.txt', 5) ?? '';
        $deny = [];
        $active = false;
        foreach (preg_split('/\R/', $txt) ?: [] as $line) {
            $line = trim(preg_replace('/#.*$/', '', $line) ?? '');
            if ($line === '') {
                continue;
            }
            if (preg_match('/^user-agent:\s*(.+)$/i', $line, $m)) {
                $active = in_array(trim(strtolower($m[1])), ['*', 'calocomtr'], true);
            } elseif ($active && preg_match('/^disallow:\s*(.*)$/i', $line, $m)) {
                if (trim($m[1]) !== '') {
                    $deny[] = trim($m[1]);
                }
            }
        }
        $rules[$host] = $deny;
    }
    $path = parse_url($url, PHP_URL_PATH) ?: '/';
    foreach ($rules[$host] as $d) {
        if (str_starts_with($path, rtrim($d, '*'))) {
            return false;
        }
    }
    return true;
}

/* ================================================================== */
/* Database                                                           */
/* ================================================================== */

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }
    $pdo = new PDO('sqlite:' . DB_FILE, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec('PRAGMA journal_mode = WAL');
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS recipes (
            id          TEXT PRIMARY KEY,
            dish_group  TEXT NOT NULL,
            title       TEXT NOT NULL,
            category    TEXT NOT NULL,
            publisher   TEXT NOT NULL,
            source_url  TEXT NOT NULL UNIQUE,
            image       TEXT NOT NULL DEFAULT '',
            calories    INTEGER NOT NULL,
            servings    INTEGER NOT NULL DEFAULT 1,
            prep_time   INTEGER NOT NULL DEFAULT 0,
            difficulty  TEXT NOT NULL DEFAULT 'Orta',
            ingredients TEXT NOT NULL DEFAULT '[]',
            lang        TEXT NOT NULL DEFAULT 'tr',
            data_type   TEXT NOT NULL DEFAULT 'estimated',
            created_at  DATETIME DEFAULT CURRENT_TIMESTAMP
        )");
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_group ON recipes(dish_group)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_cat   ON recipes(category)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_kcal  ON recipes(calories)');

    // The source pool. status: new | ok | dead. api: WP endpoint template, or 'sitemap', or ''.
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS sites (
            host   TEXT PRIMARY KEY,
            api    TEXT NOT NULL DEFAULT '',
            status TEXT NOT NULL DEFAULT 'new',
            fails  INTEGER NOT NULL DEFAULT 0,
            note   TEXT NOT NULL DEFAULT '',
            at     DATETIME DEFAULT CURRENT_TIMESTAMP
        )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS seen (url TEXT PRIMARY KEY, at DATETIME DEFAULT CURRENT_TIMESTAMP)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS discovery (q TEXT PRIMARY KEY, at DATETIME DEFAULT CURRENT_TIMESTAMP)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS siteurls (url TEXT PRIMARY KEY, host TEXT NOT NULL, slug TEXT NOT NULL)");
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_siteurls ON siteurls(host, slug)');
    $pdo->exec("CREATE TABLE IF NOT EXISTS siteindex (host TEXT PRIMARY KEY, n INTEGER NOT NULL DEFAULT 0, at DATETIME DEFAULT CURRENT_TIMESTAMP)");

    // Seed the pool on first run.
    if ((int) $pdo->query('SELECT COUNT(*) FROM sites')->fetchColumn() === 0) {
        $ins = $pdo->prepare("INSERT OR IGNORE INTO sites (host, status) VALUES (?, 'new')");
        foreach (seed_sites() as $host) {
            $ins->execute([$host]);
        }
    }
    return $pdo;
}

function already_seen(string $url): bool
{
    $s = db()->prepare('SELECT 1 FROM seen WHERE url = ?');
    $s->execute([$url]);
    return (bool) $s->fetchColumn();
}

function mark_seen(string $url): void
{
    db()->prepare('INSERT OR IGNORE INTO seen (url) VALUES (?)')->execute([$url]);
}

/* ================================================================== */
/* Site pool management                                               */
/* ================================================================== */

function add_site(string $host): void
{
    $host = domain_of(preg_match('#^https?://#i', $host) ? $host : 'https://' . ltrim(trim($host), '/'));
    if ($host === '') {
        return;
    }
    db()->prepare("INSERT OR IGNORE INTO sites (host, status) VALUES (?, 'new')")->execute([$host]);
}

function site_row(string $host): array
{
    $s = db()->prepare('SELECT * FROM sites WHERE host = ?');
    $s->execute([$host]);
    $r = $s->fetch();
    return is_array($r) ? $r : ['host' => $host, 'api' => '', 'status' => 'new', 'fails' => 0, 'note' => ''];
}

function site_update(string $host, array $fields): void
{
    $fields['at'] = date('Y-m-d H:i:s');
    $set = implode(', ', array_map(fn($k) => "$k = :$k", array_keys($fields)));
    $fields['host'] = $host;
    db()->prepare("UPDATE sites SET $set WHERE host = :host")->execute($fields);
}

/** A site earned a recipe — it's proven. */
function site_succeeded(string $host, string $api = ''): void
{
    $f = ['status' => 'ok', 'fails' => 0, 'note' => ''];
    if ($api !== '') {
        $f['api'] = $api;
    }
    site_update($host, $f);
}

/** A site came back empty. Disable it after enough consecutive misses. */
function site_failed(string $host, string $why): void
{
    $row = site_row($host);
    $fails = (int) $row['fails'] + 1;
    $dead = $fails >= SITE_FAIL_LIMIT && (int) recipe_count($host) === 0;
    site_update($host, [
        'fails' => $fails,
        'status' => $dead ? 'dead' : ($row['status'] === 'ok' ? 'ok' : 'new'),
        'note' => mb_substr($why, 0, 120),
    ]);
}

function recipe_count(string $host): int
{
    $s = db()->prepare('SELECT COUNT(*) FROM recipes WHERE publisher = ?');
    $s->execute([$host]);
    return (int) $s->fetchColumn();
}

/**
 * The sites we actually query: proven ones first (most recipes first), then
 * untested ones. Disabled sites are skipped entirely — that's what keeps a
 * search fast and its results valid.
 */
function active_sites(): array
{
    $rows = db()->query("
        SELECT s.host, s.api, s.status, COUNT(r.id) AS n
        FROM sites s LEFT JOIN recipes r ON r.publisher = s.host
        WHERE s.status <> 'dead'
        GROUP BY s.host
        ORDER BY (s.status = 'ok') DESC, n DESC, s.host ASC
    ")->fetchAll();
    return $rows;
}

function site_overview(): array
{
    return db()->query("
        SELECT s.host, s.status, s.api, s.fails, s.note, COUNT(r.id) AS n
        FROM sites s LEFT JOIN recipes r ON r.publisher = s.host
        GROUP BY s.host
        ORDER BY n DESC, s.host ASC
    ")->fetchAll();
}

/* ================================================================== */
/* Dish grouping                                                      */
/* ================================================================== */

#function known_dishes(): array
#{
#    static $d = null;
#    if ($d !== null) {
#        return $d;
#    }
#    $d = [
#        'Karnıyarık',
#        'İmam Bayıldı',
#        'Mercimek Çorbası',
#        'Ezogelin Çorbası',
#        'Yayla Çorbası',
#        'Tarhana Çorbası',
#        'Domates Çorbası',
#        'Kuru Fasulye',
#        'Etli Nohut',
#        'Tavuk Sote',
#        'Tavuk Şiş',
#        'İskender Kebap',
#        'Adana Kebap',
#        'Urfa Kebap',
#        'Beyti Kebap',
#        'Patlıcan Kebabı',
#        'Tavuk Döner',
#        'Et Döner',
#        'Kuzu Tandır',
#        'Ciğer Şiş',
#        'Etli Güveç',
#        'İçli Köfte',
#        'Lahmacun',
#        'Kıymalı Pide',
#        'Kayseri Mantısı',
#        'Mantı',
#        'Yaprak Sarma',
#        'Biber Dolma',
#        'Kısır',
#        'Menemen',
#        'Sigara Böreği',
#        'Su Böreği',
#        'Mücver',
#        'Bulgur Pilavı',
#        'Pirinç Pilavı',
#        'Fırın Sütlaç',
#        'Sütlaç',
#        'Kazandibi',
#        'Baklava',
#        'Künefe',
#        'Revani',
#        'Şekerpare',
#        'İrmik Helvası',
#        'Kabak Tatlısı',
#        'Ayva Tatlısı',
#        'Poğaça',
#        'Açma',
#        'Simit',
#        'İzmir Köfte',
#        'Çiğ Köfte',
#        'Köfte',
#        'Hünkar Beğendi',
#        'Musakka',
#        'Türlü',
#        'Ali Nazik',
#        'Cacık',
#        'Haydari',
#        'Piyaz',
#        'Şakşuka',
#        'Zeytinyağlı Fasulye',
#        'Tiramisu',
#        'Brownie',
#        'Cheesecake',
#        'Caesar Salad',
#        'Pad Thai',
#        'Carbonara',
#        'Lasagna',
#        'Risotto',
#        'Paella',
#        'Ramen',
#        'Sushi',
#        'Tacos',
#        'Chili Con Carne',
#        'Butter Chicken',
#        'Falafel',
#        'Hummus',
#        'Shakshuka',
#        'Pancakes',
#        'Waffles',
#        'French Toast',
#        'Apple Pie',
#        'Banana Bread',
#        'Guacamole',
#        'Pho',
#        'Bibimbap',
#        'Croissant',
#        'Quiche',
#        'Moussaka',
#        'Greek Salad',
#        'Chicken Curry',
#        'Overnight Oats',
#    ];
#    $d = array_values(array_unique($d));
#    usort($d, fn($a, $b) => mb_strlen($b) <=> mb_strlen($a));
#    return $d;
#}
#
#function canonical_dish(string $title): string
#{
#    $n = norm($title);
#    foreach (known_dishes() as $dish) {
#        if (str_contains($n, norm($dish))) {
#            return $dish;
#        }
#    }
#    $clean = preg_replace('/\b(tarifi|tarif|nasıl yapılır|recipe|the best|easy|kolay|nefis|pratik|resimli)\b/iu', '', $title) ?? $title;
#    $clean = trim(preg_replace('/[\s\-–—|:,]+/u', ' ', $clean) ?? $clean);
#    $words = preg_split('/\s+/u', $clean) ?: [];
#    $clean = implode(' ', array_slice($words, 0, 4));
#    return mb_convert_case($clean !== '' ? $clean : $title, MB_CASE_TITLE, 'UTF-8');
#}

/* ================================================================== */
/* Dish grouping disabled                                             */
/* ================================================================== */

/* ================================================================== */
/* Dish grouping                                                      */
/* ================================================================== */

function known_dishes(): array
{
    static $dishes = null;
    if ($dishes !== null) {
        return $dishes;
    }
    $dishes = [];
    return $dishes;
}

function canonical_dish(string $title): string
{
    $clean = html_entity_decode($title, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $clean = preg_replace('/\b(tarifi|tarif|nasıl yapılır|recipe|the best|easy|kolay|nefis|pratik|resimli|detaylı anlatım)\b/iu', '', $clean) ?? $clean;
    $clean = preg_replace('/[()[\]{}?.,+\\-:;!\"\'–—|\\/_*&]/u', '', $clean) ?? $clean;
    $clean = trim(preg_replace('/\s+/u', ' ', $clean) ?? $clean);
    $words = preg_split('/\s+/u', $clean) ?: [];
    $clean = implode(' ', array_slice($words, 0, 4));
    return mb_convert_case($clean !== '' ? $clean : $title, MB_CASE_TITLE, 'UTF-8');
}

/** Turkish sites index "kebap", not "kebab" — search every plausible spelling. */
function query_variants(string $dish): array
{
    $out = [trim($dish)];
    $slug = slugify($dish);
    $alt = strtr($slug, [
        'kebab' => 'kebap',
        'kabab' => 'kebap',
        'kofta' => 'kofte',
        'pilaf' => 'pilav',
        'yoghurt' => 'yogurt',
        'chorba' => 'corba'
    ]);
    if ($alt !== $slug) {
        $out[] = str_replace('-', ' ', $alt);
    }
    foreach (known_dishes() as $d) {
        $ds = slugify($d);
        if ($ds === $slug || $ds === $alt || str_contains($alt, $ds) || str_contains($slug, $ds)) {
            $out[] = $d;
            break;
        }
    }
    $out[] = str_replace('-', ' ', $slug);
    return array_values(array_unique(array_filter($out, fn($s) => trim((string) $s) !== '')));
}

function looks_turkish(string $dish): bool
{
    if (preg_match('/[çğıöşüÇĞİÖŞÜ]/u', $dish)) {
        return true;
    }
    $slug = strtr(slugify($dish), ['kebab' => 'kebap', 'kofta' => 'kofte']);
    foreach ([
        'kebap',
        'corba',
        'pide',
        'lahmacun',
        'borek',
        'dolma',
        'sarma',
        'pilav',
        'manti',
        'kofte',
        'doner',
        'baklava',
        'sutlac',
        'menemen',
        'mucver',
        'pogaca',
        'karniyarik',
        'iskender',
        'kunefe',
        'tandir',
        'guvec',
        'tarif'
    ] as $t) {
        if (str_contains($slug, $t)) {
            return true;
        }
    }
    return false;
}

function categorize(string $title, array $ings, string $hint = ''): string
{
    $n = norm($title . ' ' . $hint . ' ' . implode(' ', $ings));
    $map = [
        'Çorba' => ['çorba', 'soup'],
        'Tatlı' => ['tatlı', 'sütlaç', 'baklava', 'künefe', 'helva', 'kek', 'pasta', 'brownie', 'dessert', 'cake', 'cookie', 'pie', 'pudding', 'tiramisu', 'revani', 'şekerpare'],
        'Kahvaltı' => ['kahvaltı', 'menemen', 'omlet', 'breakfast', 'pancake', 'waffle', 'poğaça', 'börek', 'simit', 'açma'],
        'Salata' => ['salata', 'salad', 'kısır', 'piyaz'],
        'Zeytinyağlı' => ['zeytinyağlı', 'sarma', 'dolma', 'imam bayıldı', 'şakşuka'],
        'Fit & Diyet' => ['fit', 'diyet', 'diet', 'light', 'low calorie', 'düşük kalori', 'healthy', 'yulaf', 'oats'],
        'Dünya Mutfağı' => ['sushi', 'taco', 'curry', 'ramen', 'paella', 'pad thai', 'risotto', 'pho', 'bibimbap', 'falafel', 'hummus', 'lasagna', 'carbonara'],
    ];
    foreach ($map as $cat => $keys) {
        foreach ($keys as $k) {
            if (str_contains($n, norm($k))) {
                return $cat;
            }
        }
    }
    return 'Ana Yemek';
}

function difficulty_from(int $minutes, int $ingCount): string
{
    $score = ($minutes > 90 ? 2 : ($minutes > 40 ? 1 : 0)) + ($ingCount > 12 ? 2 : ($ingCount > 7 ? 1 : 0));
    return $score >= 3 ? 'Zor' : ($score >= 1 ? 'Orta' : 'Kolay');
}

/** Only used when the publisher gives no nutrition block — built from real ingredients. */
#function estimate_kcal(array $ings, string $cat, int $servings = 1): int
#{
#    $base = ['Çorba' => 90, 'Salata' => 80, 'Kahvaltı' => 140, 'Tatlı' => 180, 'Zeytinyağlı' => 120,
#             'Fit & Diyet' => 110, 'Dünya Mutfağı' => 170, 'Ana Yemek' => 160][$cat] ?? 150;
#    $w = ['tereyağ' => 75, 'butter' => 75, 'çikolata' => 65, 'chocolate' => 65, 'kıyma' => 60, 'mince' => 60,
#          'zeytinyağ' => 60, 'yağ' => 55, 'oil' => 55, 'krema' => 55, 'cream' => 55, 'şeker' => 55, 'sugar' => 55,
#          'makarna' => 50, 'noodle' => 50, 'peynir' => 50, 'cheese' => 50, 'fıstık' => 50, 'ceviz' => 50,
#          'walnut' => 50, 'un' => 45, 'flour' => 45, 'pirinç' => 45, 'rice' => 45, 'bulgur' => 40,
#          'kuzu' => 55, 'lamb' => 55, 'dana' => 50, 'beef' => 50, 'et' => 45, 'tavuk' => 35, 'chicken' => 35,
#          'balık' => 30, 'fish' => 30, 'bal' => 30, 'honey' => 30, 'yumurta' => 25, 'egg' => 25,
#          'patates' => 25, 'potato' => 25, 'süt' => 20, 'milk' => 20, 'yoğurt' => 18, 'yogurt' => 18];
#    $sum = $base;
#    foreach ($ings as $ing) {
#        $n = norm($ing);
#        foreach ($w as $key => $val) {
#            if (str_contains($n, $key)) {
#                $sum += $val;
#                break;
#            }
#        }
#    }
#    if ($servings > 1) {
#        $sum = (int) round($sum * (1 + 0.55 * ($servings - 1)) / $servings);
#    }
#    return (int) max(60, min(1600, $sum));
#}

function estimate_kcal(array $ingredients, string $category, int $servings = 1): int
{
    $baseCalories = [
        'Çorba' => 90,
        'Salata' => 80,
        'Kahvaltı' => 140,
        'Tatlı' => 180,
        'Zeytinyağlı' => 120,
        'Fit & Diyet' => 110,
        'Dünya Mutfağı' => 170,
        'Ana Yemek' => 160
    ][$category] ?? 150;

    // Kalori değerleri yaklaşık olarak 100 gram veya standart bir porsiyon birimi üzerinden normalize edilmiştir.
    $weights = [
        // Yağlar & Soslar
        'tereyağ' => 75,
        'butter' => 75,
        'zeytinyağ' => 90,
        'olive oil' => 90,
        'ayçiçek yağı' => 90,
        'mısır özü yağı' => 90,
        'oil' => 90,
        'yağ' => 70,
        'margarin' => 75,
        'margarine' => 75,
        'krema' => 35,
        'cream' => 35,
        'kaymak' => 60,
        'heavy cream' => 50,
        'mayonez' => 70,
        'mayonnaise' => 70,
        'ketçap' => 10,
        'ketchup' => 10,
        'salça' => 8,
        'tomato paste' => 8,

        // Tatlılar & Şekerler
        'çikolata' => 55,
        'chocolate' => 55,
        'şeker' => 40,
        'sugar' => 40,
        'pudra şekeri' => 40,
        'powdered sugar' => 40,
        'bal' => 30,
        'honey' => 30,
        'reçel' => 25,
        'jam' => 25,
        'pekmez' => 30,
        'molasses' => 30,
        'kakao' => 25,
        'cocoa' => 25,

        // Kuruyemişler & Yağlı Tohumlar
        'ceviz' => 65,
        'walnut' => 65,
        'fındık' => 65,
        'hazelnut' => 65,
        'fıstık' => 60,
        'peanut' => 60,
        'antep fıstığı' => 60,
        'pistachio' => 60,
        'badem' => 60,
        'almond' => 60,
        'kaju' => 55,
        'cashew' => 55,
        'tahin' => 60,
        'tahini' => 60,
        'susam' => 60,
        'sesame' => 60,
        'haşhaş' => 50,
        'poppy' => 50,
        'chia' => 50,
        'keten tohumu' => 50,
        'flaxseed' => 50,

        // Tahıllar, Unlar & Bakliyatlar
        'un' => 35,
        'flour' => 35,
        'nişasta' => 35,
        'starch' => 35,
        'makarna' => 35,
        'pasta' => 35,
        'noodle' => 35,
        'pirinç' => 35,
        'rice' => 35,
        'bulgur' => 35,
        'irmik' => 35,
        'semolina' => 35,
        'yulaf' => 35,
        'oat' => 35,
        'ekmek' => 30,
        'bread' => 30,
        'mercimek' => 30,
        'lentil' => 30,
        'nohut' => 30,
        'chickpea' => 30,
        'fasulye' => 30,
        'bean' => 30,
        'barbunya' => 30,
        'mısır' => 30,
        'corn' => 30,

        // Süt Ürünleri & Yumurta
        'peynir' => 35,
        'cheese' => 35,
        'kaşar' => 40,
        'lor' => 15,
        'cottage cheese' => 15,
        'yoğurt' => 15,
        'yogurt' => 15,
        'süzme yoğurt' => 20,
        'süt' => 15,
        'milk' => 15,
        'tereyağlı' => 50,
        'yumurta' => 20,
        'egg' => 20,

        // Et, Tavuk & Balık
        'kuzu' => 45,
        'lamb' => 45,
        'dana' => 35,
        'beef' => 35,
        'kıyma' => 40,
        'mince' => 40,
        'tavuk' => 25,
        'chicken' => 25,
        'hindi' => 25,
        'turkey' => 25,
        'sucuk' => 50,
        'sosis' => 35,
        'salami' => 40,
        'pastırma' => 30,
        'balık' => 20,
        'fish' => 20,
        'ton balığı' => 25,
        'tuna' => 25,
        'somon' => 25,
        'salmon' => 25,
        'karides' => 15,
        'shrimp' => 15,
        'midye' => 15,
        'mussel' => 15,

        // Sebzeler & Yeşillikler
        'patates' => 20,
        'potato' => 20,
        'patlıcan' => 10,
        'eggplant' => 10,
        'kabak' => 8,
        'zucchini' => 8,
        'havuç' => 10,
        'carrot' => 10,
        'soğan' => 10,
        'onion' => 10,
        'sarısak' => 10,
        'garlic' => 10,
        'domates' => 8,
        'tomato' => 8,
        'biber' => 8,
        'pepper' => 8,
        'salatalık' => 5,
        'cucumber' => 5,
        'ıspanak' => 8,
        'spinach' => 8,
        'marul' => 5,
        'lettuce' => 5,
        'lahana' => 8,
        'cabbage' => 8,
        'karnabahar' => 8,
        'cauliflower' => 8,
        'brokoli' => 8,
        'broccoli' => 8,
        'mantar' => 8,
        'mushroom' => 8,
        'enginar' => 10,
        'artichoke' => 10,
        'pırasa' => 10,
        'leek' => 10,

        // Meyveler
        'muz' => 20,
        'banana' => 20,
        'elma' => 10,
        'apple' => 10,
        'armut' => 10,
        'pear' => 10,
        'çilek' => 10,
        'strawberry' => 10,
        'limon' => 5,
        'lemon' => 5,
        'portakal' => 10,
        'orange' => 10,
        'üzüm' => 15,
        'grape' => 15,
        'kuru üzüm' => 30,
        'raisin' => 30,
        'hurma' => 35,
        'date' => 35,
        'incir' => 25,
        'fig' => 25
    ];

    $totalCalories = $baseCalories;

    foreach ($ingredients as $ingredient) {
        $normalizedIngredient = norm($ingredient);
        foreach ($weights as $key => $value) {
            if (str_contains($normalizedIngredient, $key)) {
                $totalCalories += $value;
                break;
            }
        }
    }

    if ($servings > 1) {
        $totalCalories = (int) round($totalCalories * (1 + 0.55 * ($servings - 1)) / $servings);
    }

    return (int) max(60, min(1600, $totalCalories));
}

/* ================================================================== */
/* schema.org/Recipe importer                                         */
/* ================================================================== */

function iso8601_minutes(mixed $v): int
{
    if (!is_string($v) || $v === '') {
        return 0;
    }
    if (preg_match('/^P(?:(\d+)D)?T?(?:(\d+)H)?(?:(\d+)M)?/i', $v, $m)) {
        return ((int) ($m[1] ?? 0)) * 1440 + ((int) ($m[2] ?? 0)) * 60 + (int) ($m[3] ?? 0);
    }
    return (int) preg_replace('/\D/', '', $v);
}

function first_string(mixed $v): string
{
    if (is_string($v)) {
        return trim($v);
    }
    if (is_array($v)) {
        foreach (['url', 'contentUrl', 'name', '@id'] as $k) {
            if (isset($v[$k]) && is_string($v[$k])) {
                return trim($v[$k]);
            }
        }
        foreach ($v as $item) {
            $s = first_string($item);
            if ($s !== '') {
                return $s;
            }
        }
    }
    return is_scalar($v) ? trim((string) $v) : '';
}

function find_recipe_nodes(mixed $node, array &$out = []): array
{
    if (!is_array($node)) {
        return $out;
    }
    $types = array_map('strtolower', to_list($node['@type'] ?? []));
    if (in_array('recipe', $types, true)) {
        $out[] = $node;
    }
    foreach ($node as $child) {
        if (is_array($child)) {
            find_recipe_nodes($child, $out);
        }
    }
    return $out;
}

function extract_recipe(string $html, string $url): ?array
{
    if (!preg_match_all('#<script[^>]+application/ld\+json[^>]*>(.*?)</script>#is', $html, $m)) {
        return null;
    }
    $nodes = [];
    foreach ($m[1] as $blob) {
        $blob = trim(preg_replace('/^\s*<!\[CDATA\[|\]\]>\s*$/', '', $blob) ?? $blob);
        $data = json_decode($blob, true);
        if (is_array($data)) {
            find_recipe_nodes($data, $nodes);
        }
    }
    if (!$nodes) {
        return null;
    }
    $r = $nodes[0];

    $title = first_string($r['name'] ?? '');
    $ings = array_values(array_filter(array_map(
        fn($x) => trim(preg_replace('/\s+/u', ' ', (string) (is_scalar($x) ? $x : '')) ?? ''),
        to_list($r['recipeIngredient'] ?? $r['ingredients'] ?? [])
    ), fn($x) => $x !== ''));
    if ($title === '' || count($ings) < 2) {
        return null;
    }

    $minutes = iso8601_minutes($r['totalTime'] ?? '')
        ?: (iso8601_minutes($r['cookTime'] ?? '') + iso8601_minutes($r['prepTime'] ?? ''));

    $servings = 1;
    if (preg_match('/\d+/', first_string($r['recipeYield'] ?? ''), $ym)) {
        $servings = max(1, min(24, (int) $ym[0]));
    }

    $kcal = 0;
    $dataType = 'estimated';
    if (is_array($r['nutrition'] ?? null)) {
        if (preg_match('/\d[\d.,]*/', first_string($r['nutrition']['calories'] ?? ''), $cm)) {
            $val = (int) round((float) str_replace(',', '.', $cm[0]));
            if ($val > 20 && $val < 5000) {
                $kcal = $val;
                $dataType = 'published';
            }
        }
    }

    $cat = categorize($title, $ings, first_string($r['recipeCategory'] ?? '') . ' ' . first_string($r['keywords'] ?? ''));
    $kcal = $kcal ?: estimate_kcal($ings, $cat, $servings);

    $lang = strtolower(first_string($r['inLanguage'] ?? ''));
    $lang = str_starts_with($lang, 'tr') ? 'tr'
        : (str_starts_with($lang, 'en') ? 'en'
            : (preg_match('/[çğıöşüÇĞİÖŞÜ]/u', $title) ? 'tr' : 'en'));

    $image = first_string($r['image'] ?? '');

    return [
        'id' => md5($url),
        'dish_group' => canonical_dish($title),
        'title' => $title,
        'category' => $cat,
        'publisher' => domain_of($url),
        'source_url' => $url,
        'image' => $image !== '' ? abs_url($image, $url) : '',
        'calories' => $kcal,
        'servings' => $servings,
        'prep_time' => $minutes,
        'difficulty' => difficulty_from($minutes, count($ings)),
        'ingredients' => json_encode($ings, JSON_UNESCAPED_UNICODE),
        'lang' => $lang,
        'data_type' => $dataType,
    ];
}

function save_recipe(array $r): bool
{
    try {
        db()->prepare(
            'INSERT OR REPLACE INTO recipes
             (id, dish_group, title, category, publisher, source_url, image, calories, servings,
              prep_time, difficulty, ingredients, lang, data_type)
             VALUES (:id,:dish_group,:title,:category,:publisher,:source_url,:image,:calories,:servings,
                     :prep_time,:difficulty,:ingredients,:lang,:data_type)'
        )->execute($r);
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

function import_url(string $url): array
{
    $url = trim($url);
    if (!preg_match('#^https?://#i', $url)) {
        return [false, 'Geçersiz URL'];
    }
    if (already_seen($url)) {
        return [false, 'Daha önce denendi'];
    }
    if (!robots_allows($url)) {
        return [false, 'robots.txt izin vermiyor: ' . domain_of($url)];
    }
    mark_seen($url);
    $html = http_get($url);
    if ($html === null) {
        return [false, 'Sayfa alınamadı: ' . domain_of($url)];
    }
    $r = extract_recipe($html, $url);
    if ($r === null) {
        return [false, 'Tarif verisi yok: ' . domain_of($url)];
    }
    if (!save_recipe($r)) {
        return [false, 'Kaydedilemedi: ' . $r['title']];
    }
    site_succeeded($r['publisher']);
    return [
        true,
        $r['title'] . ' — ' . $r['publisher'] . ' (' . $r['calories'] . ' kcal'
        . ($r['data_type'] === 'published' ? ', sitenin değeri' : ', hesaplandı') . ')'
    ];
}

/* ================================================================== */
/* Site-native search: WordPress REST, then cached sitemap            */
/* ================================================================== */

function urls_from_feed(string $feedUrl): array
{
    $body = http_get($feedUrl);
    if ($body === null) {
        return [];
    }
    $urls = [];
    if (preg_match_all('#<loc>\s*([^<\s]+)\s*</loc>#i', $body, $m)) {
        $urls = array_merge($urls, $m[1]);
    }
    if (preg_match_all('#<link[^>]*>\s*([^<\s]+)\s*</link>#i', $body, $m)) {
        $urls = array_merge($urls, $m[1]);
    }
    if (preg_match_all('#<link[^>]+href="([^"]+)"#i', $body, $m)) {
        $urls = array_merge($urls, $m[1]);
    }
    return array_values(array_unique(array_filter($urls, fn($u) => preg_match('#^https?://#i', $u))));
}

/** Read a site's public sitemap once and cache the URLs locally. */
function ensure_site_index(string $host): int
{
    $st = db()->prepare('SELECT n, at FROM siteindex WHERE host = ?');
    $st->execute([$host]);
    $row = $st->fetch();
    if ($row && (strtotime((string) $row['at']) ?: 0) > time() - 30 * 86400) {
        return (int) $row['n'];
    }

    $urls = [];
    foreach (['/sitemap.xml', '/sitemap_index.xml', '/wp-sitemap.xml'] as $path) {
        $root = 'https://' . $host . $path;
        if (!robots_allows($root)) {
            continue;
        }
        foreach (urls_from_feed($root) as $u) {
            if (preg_match('#\.xml(\.gz)?$#i', $u)) {
                if (count($urls) < SITEMAP_URL_CAP) {
                    $urls = array_merge($urls, urls_from_feed($u));
                }
            } else {
                $urls[] = $u;
            }
            if (count($urls) >= SITEMAP_URL_CAP) {
                break 2;
            }
        }
        if ($urls) {
            break;
        }
    }

    $urls = array_slice(array_values(array_unique($urls)), 0, SITEMAP_URL_CAP);
    if ($urls) {
        $ins = db()->prepare('INSERT OR IGNORE INTO siteurls (url, host, slug) VALUES (?,?,?)');
        db()->beginTransaction();
        foreach ($urls as $u) {
            $ins->execute([$u, $host, slugify(rawurldecode((string) (parse_url($u, PHP_URL_PATH) ?: '')))]);
        }
        db()->commit();
    }
    db()->prepare('INSERT INTO siteindex (host, n, at) VALUES (?,?,CURRENT_TIMESTAMP)
                   ON CONFLICT(host) DO UPDATE SET n=excluded.n, at=CURRENT_TIMESTAMP')
        ->execute([$host, count($urls)]);
    return count($urls);
}

/** Match a dish against cached sitemap slugs — pure SQL, no network. */
function search_site_index(string $host, string $dish, int $num = 3): array
{
    $tokens = array_values(array_filter(preg_split('/-+/', slugify($dish)) ?: [], fn($t) => mb_strlen($t) >= 3));
    if (!$tokens) {
        return [];
    }
    $where = implode(' AND ', array_fill(0, count($tokens), 'slug LIKE ?'));
    $params = array_map(fn($t) => '%' . $t . '%', $tokens);
    $params[] = $host;
    $st = db()->prepare("SELECT url FROM siteurls WHERE $where AND host = ? LIMIT " . max(1, $num));
    $st->execute($params);
    return array_column($st->fetchAll(), 'url');
}

/** Ask one site for a dish: its own WordPress API first, then its sitemap. */
function search_site(string $host, array $variants, int $num = 3): array
{
    $row = site_row($host);
    $known = (string) $row['api'];
    $hits = [];

    if ($known !== 'sitemap') {
        $endpoints = ($known !== '') ? [$known]
            : [
                '/wp-json/wp/v2/search?subtype=post&per_page=%d&search=%s',
                '/wp-json/wp/v2/posts?per_page=%d&search=%s'
            ];
        $alive = '';
        foreach ($endpoints as $tpl) {
            foreach ($variants as $variant) {
                $data = http_json('https://' . $host . sprintf($tpl, $num, rawurlencode($variant)));
                if (!is_array($data)) {
                    continue;
                }
                $alive = $tpl;
                foreach ($data as $item) {
                    if (!is_array($item)) {
                        continue;
                    }
                    $link = (string) ($item['url'] ?? $item['link'] ?? '');
                    if ($link !== '' && preg_match('#^https?://#i', $link)) {
                        $hits[] = $link;
                    }
                }
                if ($hits) {
                    break 2;
                }
            }
        }
        if ($hits) {
            site_update($host, ['api' => $alive]);
            return array_slice(array_unique($hits), 0, $num);
        }
        if ($alive === '' && $known === '') {
            site_update($host, ['api' => 'sitemap']);   // no WP API here
        }
    }

    foreach ($variants as $variant) {
        $found = search_site_index($host, $variant, $num);
        if ($found) {
            return $found;
        }
    }
    $st = db()->prepare('SELECT 1 FROM siteindex WHERE host = ?');
    $st->execute([$host]);
    if (!$st->fetchColumn()) {
        ensure_site_index($host);
        foreach ($variants as $variant) {
            $found = search_site_index($host, $variant, $num);
            if ($found) {
                return $found;
            }
        }
    }
    return [];
}

/* ================================================================== */
/* Discovery — proven sites only, no web search                       */
/* ================================================================== */

function discovery_is_fresh(string $dish): bool
{
    $s = db()->prepare('SELECT at FROM discovery WHERE q = ?');
    $s->execute([norm($dish)]);
    $at = $s->fetchColumn();
    return $at !== false && (strtotime((string) $at) ?: 0) > time() - DISCOVER_TTL_DAYS * 86400;
}

function mark_discovered(string $dish): void
{
    db()->prepare('INSERT INTO discovery (q, at) VALUES (?, CURRENT_TIMESTAMP)
                   ON CONFLICT(q) DO UPDATE SET at = CURRENT_TIMESTAMP')->execute([norm($dish)]);
}

function sources_for_dish(string $dish): int
{
    $s = db()->prepare('SELECT COUNT(DISTINCT publisher) FROM recipes
                        WHERE LOWER(dish_group) LIKE :q OR LOWER(title) LIKE :q');
    $s->execute([':q' => '%' . norm($dish) . '%']);
    return (int) $s->fetchColumn();
}

/**
 * Search a dish across the sites we already know work. Each site is asked
 * directly, so results are recipe pages by construction — no ranking, no
 * search engine, no waiting on a third party.
 */
function discover_and_import(string $dish, bool $force = false): array
{
    $dish = trim($dish);
    $log = [];
    if ($dish === '' || mb_strlen($dish) < 3) {
        return $log;
    }
    if (!$force && (discovery_is_fresh($dish) || sources_for_dish($dish) >= MIN_SOURCES_PER_DISH)) {
        return $log;
    }
    mark_discovered($dish);

    $variants = query_variants($dish);
    $turkish = looks_turkish($dish);
    $sites = active_sites();

    // Prefer same-language sites, then whichever have proven most productive.
    usort($sites, function ($a, $b) use ($turkish) {
        $atr = str_ends_with($a['host'], '.tr') || preg_match('/yemek|mutfa|lezzet|tarif/', $a['host']);
        $btr = str_ends_with($b['host'], '.tr') || preg_match('/yemek|mutfa|lezzet|tarif/', $b['host']);
        if ($atr !== $btr) {
            return $turkish ? ($btr <=> $atr) : ($atr <=> $btr);
        }
        return ((int) $b['n']) <=> ((int) $a['n']);
    });

    $added = 0;
    $checked = 0;
    $hitList = [];

    foreach ($sites as $site) {
        if ($added >= DISCOVER_LIMIT) {
            break;
        }
        $host = (string) $site['host'];
        $checked++;

        $urls = search_site($host, $variants, 2);
        if (!$urls) {
            site_failed($host, 'sonuç yok: ' . $dish);
            continue;
        }
        $gotOne = false;
        foreach ($urls as $u) {
            if ($added >= DISCOVER_LIMIT || already_seen($u)) {
                continue;
            }
            [$ok, $msg] = import_url($u);
            if ($ok) {
                $added++;
                $gotOne = true;
                $hitList[] = $msg;
            }
        }
        if (!$gotOne) {
            site_failed($host, 'tarif verisi çıkmadı');
        }
    }

    // TheMealDB is a source like any other, and always cheap to ask.
    $meals = http_json('https://www.themealdb.com/api/json/v1/1/search.php?s=' . rawurlencode($dish));
    foreach ((array) ($meals['meals'] ?? []) as $meal) {
        if (!is_array($meal)) {
            continue;
        }
        $row = mealdb_to_row($meal);
        if ($row && !already_seen($row['source_url'])) {
            mark_seen($row['source_url']);
            if (save_recipe($row)) {
                site_succeeded($row['publisher']);
                $hitList[] = $row['title'] . ' — ' . $row['publisher'];
            }
        }
    }

    $log[] = [
        'info',
        sprintf(
            '“%s” · %d kaynak sitede arandı · %d yeni tarif · yazımlar: %s',
            $dish,
            $checked,
            count($hitList),
            implode(' / ', $variants)
        )
    ];
    foreach (array_slice($hitList, 0, 6) as $msg) {
        $log[] = ['ok', $msg];
    }
    if (!$hitList) {
        $log[] = [
            'warn',
            'Kaynak sitelerde bu yemek bulunamadı. Türkçe yazımıyla deneyin '
            . 'veya aşağıdan yeni bir kaynak site ekleyin.'
        ];
    }
    return $log;
}

/* ================================================================== */
/* TheMealDB                                                          */
/* ================================================================== */

function mealdb_areas(): array
{
    return [
        'Turkish',
        'Italian',
        'Greek',
        'French',
        'Mexican',
        'Japanese',
        'Indian',
        'Chinese',
        'Thai',
        'Spanish',
        'British',
        'American',
        'Moroccan'
    ];
}

function mealdb_to_row(array $m): ?array
{
    $url = trim((string) ($m['strSource'] ?? ''));
    if ($url === '' || !preg_match('#^https?://#i', $url)) {
        return null;
    }
    $title = trim((string) ($m['strMeal'] ?? ''));
    if ($title === '') {
        return null;
    }
    $ings = [];
    for ($i = 1; $i <= 20; $i++) {
        $name = trim((string) ($m['strIngredient' . $i] ?? ''));
        $meas = trim((string) ($m['strMeasure' . $i] ?? ''));
        if ($name !== '') {
            $ings[] = trim($meas . ' ' . $name);
        }
    }
    if (count($ings) < 2) {
        return null;
    }
    $cat = categorize($title, $ings, (string) ($m['strCategory'] ?? '') . ' ' . (string) ($m['strTags'] ?? ''));
    return [
        'id' => md5($url),
        'dish_group' => canonical_dish($title),
        'title' => $title,
        'category' => $cat,
        'publisher' => domain_of($url),
        'source_url' => $url,
        'image' => trim((string) ($m['strMealThumb'] ?? '')),
        'calories' => estimate_kcal($ings, $cat, 4),
        'servings' => 4,
        'prep_time' => 0,
        'difficulty' => difficulty_from(0, count($ings)),
        'ingredients' => json_encode($ings, JSON_UNESCAPED_UNICODE),
        'lang' => 'en',
        'data_type' => 'estimated',
    ];
}

function mealdb_import_area(string $area, int $limit = MAX_IMPORT_PER_REQUEST): array
{
    $log = [];
    $list = http_json('https://www.themealdb.com/api/json/v1/1/filter.php?a=' . rawurlencode($area));
    $ids = array_column((array) ($list['meals'] ?? []), 'idMeal');
    if (!$ids) {
        return [['warn', $area . ' için sonuç yok.']];
    }
    shuffle($ids);
    $ok = 0;
    foreach ($ids as $id) {
        if ($ok >= $limit) {
            break;
        }
        $detail = http_json('https://www.themealdb.com/api/json/v1/1/lookup.php?i=' . rawurlencode((string) $id));
        $meal = ($detail['meals'] ?? [null])[0] ?? null;
        if (!is_array($meal)) {
            continue;
        }
        $row = mealdb_to_row($meal);
        if ($row === null || already_seen($row['source_url'])) {
            continue;
        }
        mark_seen($row['source_url']);
        if (save_recipe($row)) {
            add_site($row['publisher']);
            site_succeeded($row['publisher']);
            $ok++;
            $log[] = ['ok', $row['title'] . ' — ' . $row['publisher']];
        }
    }
    if ($ok === 0) {
        $log[] = ['warn', $area . ': yeni kayıt eklenmedi.'];
    }
    return $log;
}

function auto_topup(): void
{
    if ((int) db()->query('SELECT COUNT(*) FROM recipes')->fetchColumn() >= AUTO_TOPUP_TARGET) {
        return;
    }
    $areas = mealdb_areas();
    mealdb_import_area($areas[array_rand($areas)], 4);
}

/** Bulk-import a newly added site from its sitemap, so it becomes usable at once. */
function bootstrap_site(string $host, int $limit = MAX_IMPORT_PER_REQUEST): array
{
    add_site($host);
    $log = [];
    $n = ensure_site_index($host);
    $st = db()->prepare('SELECT url FROM siteurls WHERE host = ? LIMIT 400');
    $st->execute([$host]);
    $urls = array_column($st->fetchAll(), 'url');
    if (!$urls) {
        site_failed($host, 'sitemap bulunamadı');
        return [['warn', $host . ': sitemap okunamadı, tarif alınamadı.']];
    }
    shuffle($urls);
    $ok = 0;
    foreach ($urls as $u) {
        if ($ok >= $limit) {
            break;
        }
        [$success, $msg] = import_url($u);
        if ($success) {
            $ok++;
            $log[] = ['ok', $msg];
        }
    }
    $log[] = ['info', $host . ': ' . $n . ' adres tarandı, ' . $ok . ' tarif eklendi.'];
    if ($ok === 0) {
        site_failed($host, 'tarif verisi bulunamadı');
    }
    return $log;
}

/* ================================================================== */
/* Query layer                                                        */
/* ================================================================== */

function read_filters(array $g): array
{
    return [
        'q' => trim((string) ($g['q'] ?? '')),
        'cat' => trim((string) ($g['cat'] ?? '')),
        'kmin' => max(0, min(2000, (int) ($g['kmin'] ?? 0))),
        'kmax' => max(0, min(2000, (int) ($g['kmax'] ?? 2000))),
        'tmax' => max(0, min(300, (int) ($g['tmax'] ?? 240))),
        'diff' => trim((string) ($g['diff'] ?? '')),
        'inc' => trim((string) ($g['inc'] ?? '')),
        'exc' => trim((string) ($g['exc'] ?? '')),
        'data_type' => trim((string) ($g['data_type'] ?? '')),
        'pantry' => !empty($g['pantry']),
        'lang' => trim((string) ($g['lang'] ?? '')),
    ];
}

function fetch_recipes(array $f): array
{
    $sql = 'SELECT * FROM recipes WHERE calories BETWEEN :kmin AND :kmax
            AND (prep_time = 0 OR prep_time <= :tmax)';
    $params = [
        ':kmin' => min($f['kmin'], $f['kmax']),
        ':kmax' => max($f['kmin'], $f['kmax']),
        ':tmax' => $f['tmax'] > 0 ? $f['tmax'] : 300
    ];
    if ($f['cat'] !== '') {
        $sql .= ' AND category = :cat';
        $params[':cat'] = $f['cat'];
    }
    if ($f['diff'] !== '') {
        $sql .= ' AND difficulty = :diff';
        $params[':diff'] = $f['diff'];
    }
    if ($f['lang'] !== '') {
        $sql .= ' AND lang = :lang';
        $params[':lang'] = $f['lang'];
    }
    if ($f['data_type'] !== '') {
        $sql .= ' AND data_type = :data_type';
        $params[':data_type'] = $f['data_type'];
    }
    if ($f['q'] !== '') {
        $sql .= ' AND (LOWER(dish_group) LIKE :q OR LOWER(title) LIKE :q
                       OR LOWER(category) LIKE :q OR LOWER(ingredients) LIKE :q
                       OR LOWER(publisher) LIKE :q)';
        $params[':q'] = '%' . norm($f['q']) . '%';
    }
    $sql .= ' ORDER BY dish_group ASC, calories ASC LIMIT 1200';

    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    $inc = csv_to_list($f['inc']);
    $exc = csv_to_list($f['exc']);

    return array_values(array_filter($rows, function (array $r) use ($inc, $exc, $f) {
        $normd = array_map('norm', to_list($r['ingredients']));
        $blob = implode(' | ', $normd);
        foreach ($exc as $bad) {
            if (str_contains($blob, $bad)) {
                return false;
            }
        }
        foreach ($inc as $need) {
            if (!str_contains($blob, $need)) {
                return false;
            }
        }
        if ($f['pantry']) {
            if (!$inc) {
                return false;
            }
            foreach ($normd as $ing) {
                $ok = false;
                foreach ($inc as $have) {
                    if (str_contains($ing, $have)) {
                        $ok = true;
                        break;
                    }
                }
                if (!$ok) {
                    return false;
                }
            }
        }
        return true;
    }));
}

function group_recipes(array $rows): array
{
    $groups = [];
    foreach ($rows as $r) {
        $g = (string) $r['dish_group'];
        if (!isset($groups[$g])) {
            $groups[$g] = ['dish' => $g, 'items' => [], 'category' => $r['category'], 'image' => '', 'key' => md5($g)];
        }
        $groups[$g]['items'][] = $r;
        if ($groups[$g]['image'] === '' && $r['image'] !== '') {
            $groups[$g]['image'] = $r['image'];
        }
    }
    foreach ($groups as &$g) {
        usort($g['items'], fn($a, $b) => (int) $a['calories'] <=> (int) $b['calories']);
        $kcals = array_map(fn($i) => (int) $i['calories'], $g['items']);
        $g['min'] = min($kcals);
        $g['max'] = max($kcals);
        $g['avg'] = (int) round(array_sum($kcals) / count($kcals));
        $g['spread'] = $g['min'] > 0 ? (int) round(($g['max'] - $g['min']) / $g['min'] * 100) : 0;
        $g['count'] = count($g['items']);
        $g['sites'] = count(array_unique(array_column($g['items'], 'publisher')));
    }
    unset($g);
    uasort($groups, fn($a, $b) => [$b['sites'], $b['spread']] <=> [$a['sites'], $a['spread']]);
    return $groups;
}

/* ================================================================== */
/* Request handling                                                   */
/* ================================================================== */

$importLog = [];
$error = null;

try {
    db();

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        @set_time_limit(180);
        switch ((string) ($_POST['action'] ?? '')) {
            case 'url':
                foreach (preg_split('/\R/', (string) ($_POST['urls'] ?? '')) ?: [] as $line) {
                    if (trim($line) === '') {
                        continue;
                    }
                    [$ok, $msg] = import_url(trim($line));
                    $importLog[] = [$ok ? 'ok' : 'warn', $msg];
                }
                break;
            case 'site':
                $importLog = bootstrap_site((string) ($_POST['site'] ?? ''));
                break;
            case 'mealdb':
                $area = (string) ($_POST['area'] ?? 'Turkish');
                $importLog = mealdb_import_area(in_array($area, mealdb_areas(), true) ? $area : 'Turkish');
                break;
            case 'revive':
                $host = (string) ($_POST['host'] ?? '');
                site_update($host, ['status' => 'new', 'fails' => 0, 'api' => '', 'note' => '']);
                db()->prepare('DELETE FROM siteindex WHERE host = ?')->execute([$host]);
                $importLog[] = ['info', $host . ' yeniden etkinleştirildi.'];
                break;
            case 'drop':
                $host = (string) ($_POST['host'] ?? '');
                db()->prepare("UPDATE sites SET status='dead', note='elle kapatıldı' WHERE host = ?")->execute([$host]);
                $importLog[] = ['info', $host . ' kaynak listesinden çıkarıldı.'];
                break;
        }
    }

    $filters = read_filters($_GET);

    if ($filters['q'] !== '') {
        @set_time_limit(120);
        $importLog = array_merge($importLog, discover_and_import($filters['q']));
    } elseif (isset($_GET['find'])) {
        @set_time_limit(120);
        $importLog = array_merge($importLog, discover_and_import(trim((string) $_GET['find']), true));
    }

    auto_topup();

    $rows = fetch_recipes($filters);
    $groups = group_recipes($rows);
    $sites = site_overview();
    $totalAll = (int) db()->query('SELECT COUNT(*) FROM recipes')->fetchColumn();
    $totalSite = (int) db()->query('SELECT COUNT(DISTINCT publisher) FROM recipes')->fetchColumn();
    $totalDish = (int) db()->query('SELECT COUNT(DISTINCT dish_group) FROM recipes')->fetchColumn();
} catch (Throwable $e) {
    $error = $e->getMessage();
    $filters = read_filters($_GET);
    $rows = $groups = $sites = [];
    $totalAll = $totalSite = $totalDish = 0;
}

$CATS = [
    'Çorba' => '🍲',
    'Ana Yemek' => '🍖',
    'Kahvaltı' => '🍳',
    'Zeytinyağlı' => '🥗',
    'Tatlı' => '🍰',
    'Fit & Diyet' => '🥑',
    'Salata' => '🥬',
    'Dünya Mutfağı' => '🌍'
];

function qs(array $over = []): string
{
    global $filters;
    $base = [
        'q' => $filters['q'],
        'cat' => $filters['cat'],
        'kmin' => $filters['kmin'],
        'kmax' => $filters['kmax'],
        'tmax' => $filters['tmax'],
        'diff' => $filters['diff'],
        'inc' => $filters['inc'],
        'exc' => $filters['exc'],
        'pantry' => $filters['pantry'] ? 1 : '',
        'lang' => $filters['lang']
    ];
    return '?' . http_build_query(array_filter(array_merge($base, $over), fn($v) => $v !== '' && $v !== null));
}
?>
<!DOCTYPE html>
<html lang="tr">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="icon" type="image/x-icon" href="favicon.ico">
    <title>Calo.com.tr — Aynı yemeğin kalorisini sitelere göre karşılaştır</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" rel="stylesheet">

    <style>
        :root {
            --ink: #0f172a;
            --emerald: #10b981;
            --emerald-dark: #059669;
            --bg: #f8fafc;
            --line: #e2e8f0;
        }

        body {
            background: var(--bg);
            color: #1e293b;
            font-family: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
        }

        .hero {
            background: linear-gradient(160deg, #0f172a 0%, #152238 60%, #0b3b30 100%);
            color: #e2e8f0;
            padding: 2.2rem 0 1.4rem;
        }

        .hero .brand {
            font-weight: 800;
            letter-spacing: -.5px;
        }

        .hero .brand span {
            color: var(--emerald);
        }

        .stat {
            background: rgba(255, 255, 255, .06);
            border: 1px solid rgba(255, 255, 255, .10);
            border-radius: .75rem;
            padding: .55rem .9rem;
        }

        .stat b {
            display: block;
            font-size: 1.15rem;
            color: var(--emerald);
            line-height: 1.1;
        }

        .stat small {
            color: #94a3b8;
            font-size: .72rem;
            text-transform: uppercase;
            letter-spacing: .06em;
        }

        .pill {
            border: 1px solid rgba(255, 255, 255, .18);
            color: #cbd5e1;
            border-radius: 2rem;
            padding: .28rem .85rem;
            font-size: .86rem;
            text-decoration: none;
            display: inline-block;
            margin: .18rem .18rem 0 0;
            transition: .15s;
            white-space: nowrap;
        }

        .pill:hover {
            background: rgba(16, 185, 129, .15);
            color: #fff;
            border-color: var(--emerald);
        }

        .pill.active {
            background: var(--emerald);
            border-color: var(--emerald);
            color: #04241b;
            font-weight: 600;
        }

        .sidebar,
        .panel {
            background: #fff;
            border: 1px solid var(--line);
            border-radius: 1rem;
            padding: 1.1rem;
        }

        .sidebar {
            position: sticky;
            top: 1rem;
        }

        .sidebar h6 {
            font-size: .74rem;
            text-transform: uppercase;
            letter-spacing: .07em;
            color: #64748b;
            font-weight: 700;
            margin: 1.1rem 0 .45rem;
        }

        .sidebar h6:first-of-type {
            margin-top: 0;
        }

        .card-recipe {
            border: 1px solid var(--line);
            border-radius: 1rem;
            overflow: hidden;
            background: #fff;
            height: 100%;
            transition: .18s;
        }

        .card-recipe:hover {
            transform: translateY(-3px);
            box-shadow: 0 12px 28px rgba(15, 23, 42, .10);
            border-color: #cbd5e1;
        }

        .card-recipe .thumb {
            height: 170px;
            width: 100%;
            object-fit: cover;
            background: #e2e8f0;
        }

        .badge-count {
            position: absolute;
            top: .6rem;
            right: .6rem;
            background: rgba(15, 23, 42, .85);
            color: #fff;
            border-radius: 2rem;
            padding: .25rem .7rem;
            font-size: .75rem;
            font-weight: 600;
        }

        .kcal-bar {
            height: 8px;
            border-radius: 4px;
            background: linear-gradient(90deg, #10b981, #facc15 55%, #ef4444);
        }

        .rank-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: .86rem;
            padding: .28rem 0;
            border-bottom: 1px dashed var(--line);
        }

        .rank-row:last-child {
            border-bottom: 0;
        }

        .kcal-tag {
            font-variant-numeric: tabular-nums;
            font-weight: 700;
        }

        .fit-tag {
            background: #ecfdf5;
            color: var(--emerald-dark);
            border: 1px solid #a7f3d0;
            border-radius: .4rem;
            padding: .05rem .4rem;
            font-size: .7rem;
            font-weight: 700;
        }

        .tahmini-tag {
            background: #fdfbec;
            color: #EB7D00;
            border: 1px solid #f2f3a7;
            border-radius: .4rem;
            padding: .05rem .4rem;
            font-size: .7rem;
            font-weight: 700;
        }

        .src-tag {
            font-size: .68rem;
            border-radius: .35rem;
            padding: .05rem .35rem;
            border: 1px solid var(--line);
            color: #64748b;
            background: #f8fafc;
        }

        .src-tag.pub {
            background: #eff6ff;
            border-color: #bfdbfe;
            color: #1d4ed8;
        }

        .btn-emerald {
            background: var(--emerald);
            border-color: var(--emerald);
            color: #04241b;
            font-weight: 600;
        }

        .btn-emerald:hover {
            background: var(--emerald-dark);
            border-color: var(--emerald-dark);
            color: #fff;
        }

        .ing-tag {
            display: inline-block;
            background: #f1f5f9;
            border: 1px solid var(--line);
            border-radius: .4rem;
            padding: .12rem .45rem;
            font-size: .78rem;
            margin: .12rem;
        }

        .site-row {
            display: flex;
            align-items: center;
            gap: .5rem;
            font-size: .84rem;
            padding: .3rem 0;
            border-bottom: 1px dashed var(--line);
        }

        .site-row:last-child {
            border-bottom: 0;
        }

        .dot {
            width: .55rem;
            height: .55rem;
            border-radius: 50%;
            display: inline-block;
            flex: 0 0 auto;
        }

        .dot.ok {
            background: var(--emerald);
        }

        .dot.new {
            background: #facc15;
        }

        .dot.dead {
            background: #cbd5e1;
        }

        @media (prefers-reduced-motion:reduce) {
            * {
                transition: none !important;
            }
        }

        /* Kalori Slider */
        .custom-range-slider {
            position: relative;
            height: 40px;
            display: flex;
            align-items: center;
        }

        .custom-range-slider .slider-track {
            position: absolute;
            width: 100%;
            height: 6px;
            background: #dee2e6;
            border-radius: 3px;
            z-index: 1;
        }

        .custom-range-slider input[type="range"] {
            position: absolute;
            width: 100%;
            background: none;
            pointer-events: none;
            appearance: none;
            z-index: 2;
            margin: 0;
        }

        .custom-range-slider input[type="range"]::-webkit-slider-runnable-track {
            height: 6px;
            background: transparent;
        }

        .custom-range-slider input[type="range"]::-moz-range-track {
            height: 6px;
            background: transparent;
        }

        .custom-range-slider input[type="range"]::-webkit-slider-thumb {
            pointer-events: auto;
            cursor: pointer;
            appearance: none;
            width: 18px;
            height: 18px;
            border-radius: 50%;
            background: #0d6efd;
            border: 2px solid #fff;
            box-shadow: 0 0.125rem 0.25rem rgba(0, 0, 0, 0.075);
            margin-top: -6px;
        }

        .custom-range-slider input[type="range"]::-moz-range-thumb {
            pointer-events: auto;
            cursor: pointer;
            width: 18px;
            height: 18px;
            border-radius: 50%;
            background: #0d6efd;
            border: 2px solid #fff;
            box-shadow: 0 0.125rem 0.25rem rgba(0, 0, 0, 0.075);
        }

        .custom-range-slider input.range-max {
            z-index: 3;
        }
    </style>
</head>

<body>

    <header class="hero">
        <div class="container">
            <div class="d-flex flex-wrap justify-content-between align-items-center gap-3">
                <div>
                    <h1 class="brand h3 mb-1"><i class="fa-solid fa-scale-balanced me-2"></i>Calo<span>.com.tr</span></h1>
                    <p class="mb-0 text-secondary" style="font-size:.9rem;">Aynı yemeğin kalorisini yayınlandığı
                        sitelere göre karşılaştır.</p>
                </div>
                <div class="d-flex gap-2 flex-wrap">
                    <div class="stat"><b><?= number_format($totalAll) ?></b><small>Tarif</small></div>
                    <div class="stat"><b><?= number_format($totalSite) ?></b><small>Kaynak site</small></div>
                    <div class="stat"><b><?= number_format($totalDish) ?></b><small>Yemek</small></div>
                </div>
            </div>

            <form class="mt-3" method="get">
                <div class="input-group input-group-lg">
                    <span class="input-group-text bg-white border-0"><i
                            class="fa-solid fa-magnifying-glass text-secondary"></i></span>
                    <input type="search" class="form-control border-0" name="q" value="<?= h($filters['q']) ?>"
                        placeholder="Yemek, malzeme, kategori veya site ara">
                    <button class="btn btn-emerald px-4" type="submit">Karşılaştır</button>
                </div>
                <?php foreach (['cat', 'kmin', 'kmax', 'tmax', 'diff', 'inc', 'exc', 'lang'] as $k): ?>
                    <input type="hidden" name="<?= $k ?>" value="<?= h((string) $filters[$k]) ?>">
                <?php endforeach; ?>
                <?php if ($filters['pantry']): ?><input type="hidden" name="pantry" value="1"><?php endif; ?>
            </form>

            <nav class="mt-3">
                <a class="pill <?= $filters['cat'] === '' ? 'active' : '' ?>"
                    href="<?= h(qs(['cat' => ''])) ?>">Tümü</a>
                <?php foreach ($CATS as $name => $emoji): ?>
                    <a class="pill <?= $filters['cat'] === $name ? 'active' : '' ?>"
                        href="<?= h(qs(['cat' => $name])) ?>"><?= $emoji ?>     <?= h($name) ?></a>
                <?php endforeach; ?>
            </nav>
        </div>
    </header>

    <main class="container my-4">
        <?php if ($error): ?>
            <div class="alert alert-danger">Hata: <?= h($error) ?></div><?php endif; ?>

        <div class="panel mb-4" hidden>
            <div class="d-flex justify-content-between align-items-center">
                <h2 class="h6 mb-0"><i class="fa-solid fa-database me-2 text-secondary"></i>Kaynak siteler
                    <span class="text-secondary fw-normal">— arama yalnızca bu sitelerde yapılır</span>
                </h2>
                <button class="btn btn-sm btn-outline-secondary" data-bs-toggle="collapse" data-bs-target="#addSrc">Aç /
                    kapat</button>
            </div>

            <div class="collapse <?= 'hide' #$importLog ? 'show' : '' ?> mt-3" id="addSrc">
                <div class="row g-3">
                    <div class="col-lg-5">
                        <div class="small fw-semibold mb-2">Havuzdaki siteler</div>
                        <?php foreach ($sites as $s):
                            $cls = $s['status'] === 'ok' ? 'ok' : ($s['status'] === 'dead' ? 'dead' : 'new'); ?>
                            <div class="site-row">
                                <span class="dot <?= $cls ?>" title="<?= h($s['status']) ?>"></span>
                                <span class="flex-grow-1 text-truncate">
                                    <?= h($s['host']) ?>
                                    <span class="text-secondary">· <?= (int) $s['n'] ?> tarif</span>
                                    <?php if ($s['status'] === 'dead'): ?>
                                        <span class="text-secondary" style="font-size:.75rem;">(kapalı:
                                            <?= h($s['note']) ?>)</span>
                                    <?php elseif ($s['api'] === 'sitemap'): ?>
                                        <span class="src-tag">sitemap</span>
                                    <?php elseif ($s['api'] !== ''): ?>
                                        <span class="src-tag">API</span>
                                    <?php endif; ?>
                                </span>
                                <form method="post" class="d-inline">
                                    <input type="hidden" name="host" value="<?= h($s['host']) ?>">
                                    <?php if ($s['status'] === 'dead'): ?>
                                        <button class="btn btn-sm btn-outline-secondary py-0 px-1" name="action" value="revive"
                                            title="Yeniden dene">
                                            <i class="fa-solid fa-rotate-right"></i></button>
                                    <?php else: ?>
                                        <button class="btn btn-sm btn-outline-secondary py-0 px-1" name="action" value="drop"
                                            title="Kapat">
                                            <i class="fa-solid fa-xmark"></i></button>
                                    <?php endif; ?>
                                </form>
                            </div>
                        <?php endforeach; ?>
                        <div class="form-text mt-2" style="font-size:.72rem;">
                            Yeşil: tarif veren siteler. Sarı: henüz denenmedi. Gri: <?= SITE_FAIL_LIMIT ?> denemede
                            sonuç
                            vermediği için kapatıldı — aramada hiç sorgulanmaz.
                        </div>
                    </div>

                    <div class="col-lg-7">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <form method="post">
                                    <input type="hidden" name="action" value="site">
                                    <label class="form-label small fw-semibold">Yeni kaynak site ekle</label>
                                    <input class="form-control form-control-sm mb-2" name="site"
                                        placeholder="ornek-yemek-sitesi.com">
                                    <button class="btn btn-emerald btn-sm w-100">Ekle ve tarifleri çek</button>
                                    <div class="form-text" style="font-size:.72rem;">Sitemap okunur, ilk
                                        <?= MAX_IMPORT_PER_REQUEST ?> tarif alınır.
                                    </div>
                                </form>
                            </div>
                            <div class="col-md-6">
                                <form method="post">
                                    <input type="hidden" name="action" value="mealdb">
                                    <label class="form-label small fw-semibold">TheMealDB mutfağı</label>
                                    <select class="form-select form-select-sm mb-2" name="area">
                                        <?php foreach (mealdb_areas() as $a): ?>
                                            <option value="<?= h($a) ?>"><?= h($a) ?></option><?php endforeach; ?>
                                    </select>
                                    <button class="btn btn-emerald btn-sm w-100">İçe aktar</button>
                                </form>
                            </div>
                            <div class="col-12">
                                <form method="post">
                                    <input type="hidden" name="action" value="url">
                                    <label class="form-label small fw-semibold">Tek tek tarif adresi (her satıra bir
                                        tane)</label>
                                    <textarea class="form-control form-control-sm mb-2" name="urls" rows="2"
                                        placeholder="https://..."></textarea>
                                    <button class="btn btn-emerald btn-sm">Tarifleri ekle</button>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>

                <?php if ($importLog): ?>
                    <ul class="list-unstyled small mt-3 mb-0">
                        <?php foreach (array_slice($importLog, 0, 12) as [$lvl, $msg]): ?>
                            <li
                                class="<?= $lvl === 'ok' ? 'text-success' : ($lvl === 'info' ? 'text-secondary' : 'text-warning-emphasis') ?>">
                                <i
                                    class="fa-solid <?= $lvl === 'ok' ? 'fa-plus' : ($lvl === 'info' ? 'fa-circle-info' : 'fa-triangle-exclamation') ?> me-1"></i><?= h($msg) ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        </div>

        <div class="row g-4">
            <aside class="col-lg-3">
                <form class="sidebar" method="get">
                    <input type="hidden" name="q" value="<?= h($filters['q']) ?>">
                    <input type="hidden" name="cat" value="<?= h($filters['cat']) ?>">

                    <h6>Kalori aralığı (kcal)</h6>
                    <!--
                    <div class="d-flex justify-content-between small text-secondary">
                        <span>En az: <b id="kminOut"><?= (int) $filters['kmin'] ?></b></span>
                        <span>En çok: <b id="kmaxOut"><?= (int) $filters['kmax'] ?></b></span>
                    </div>
                    <input type="range" class="form-range" name="kmin" id="kmin" min="0" max="2000" step="10"
                        value="<?= (int) $filters['kmin'] ?>">
                    <input type="range" class="form-range" name="kmax" id="kmax" min="0" max="2000" step="10"
                        value="<?= (int) $filters['kmax'] ?>">

                        -->

                    <!-- Kalori Slider Başlangıç -->
                    <div class="d-flex justify-content-between small text-secondary mb-1">
                        <span>En az: <b id="kminOut"><?= (int) $filters['kmin'] ?></b></span>
                        <span>En çok: <b id="kmaxOut"><?= (int) $filters['kmax'] ?></b></span>
                    </div>

                    <div class="custom-range-slider">
                        <div class="slider-track"></div>
                        <input type="range" class="form-range range-min" name="kmin" id="kmin" min="0" max="2000"
                            step="10" value="<?= (int) $filters['kmin'] ?>">
                        <input type="range" class="form-range range-max" name="kmax" id="kmax" min="0" max="2000"
                            step="10" value="<?= (int) $filters['kmax'] ?>">
                    </div>

                    <!-- Kalori Slider Bitiş -->

                    <div class="form-check form-switch mt-3">
                        <input class="form-check-input" type="checkbox" role="switch" id="data_type" name="data_type"
                            value="published" <?= $filters['data_type'] ? 'checked' : '' ?>>
                        <label class="form-check-label small" for="data_type">Sadece kalorisi doğrulanmış
                            tarifler</label>
                    </div>

                    <h6>Hazırlama süresi</h6>
                    <div class="small text-secondary mb-1">En fazla <b id="tmaxOut"><?= (int) $filters['tmax'] ?></b>
                        dakika</div>
                    <input type="range" class="form-range" name="tmax" id="tmax" min="5" max="240" step="5"
                        value="<?= (int) $filters['tmax'] ?>">

                    <h6>Zorluk</h6>
                    <select class="form-select form-select-sm" name="diff">
                        <?php foreach (['' => 'Tümü', 'Kolay' => 'Kolay', 'Orta' => 'Orta', 'Zor' => 'Zor'] as $v => $lbl): ?>
                            <option value="<?= h($v) ?>" <?= $filters['diff'] === $v ? 'selected' : '' ?>><?= h($lbl) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>

                    <h6>İçersin (virgülle ayır)</h6>
                    <input type="text" class="form-control form-control-sm" name="inc" value="<?= h($filters['inc']) ?>"
                        placeholder="patlıcan, kıyma">

                    <h6>İçermesin</h6>
                    <input type="text" class="form-control form-control-sm" name="exc" value="<?= h($filters['exc']) ?>"
                        placeholder="sarımsak, un">

                    <div class="form-check form-switch mt-3">
                        <input class="form-check-input" type="checkbox" role="switch" id="pantry" name="pantry"
                            value="1" <?= $filters['pantry'] ? 'checked' : '' ?>>
                        <label class="form-check-label small" for="pantry">Sadece elimdeki malzemeler</label>
                    </div>

                    <h6>Dil</h6>
                    <select class="form-select form-select-sm" name="lang">
                        <?php foreach (['' => 'Tümü', 'tr' => 'Türkçe', 'en' => 'İngilizce'] as $v => $lbl): ?>
                            <option value="<?= h($v) ?>" <?= $filters['lang'] === $v ? 'selected' : '' ?>><?= h($lbl) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>

                    <div class="d-grid gap-2 mt-3">
                        <button class="btn btn-emerald btn-sm" type="submit">Filtreleri uygula</button>
                        <a class="btn btn-outline-secondary btn-sm" href="?">Filtreleri temizle</a>
                    </div>
                </form>
            </aside>

            <section class="col-lg-9">
                <div class="text-secondary small mb-3">
                    <strong class="text-dark"><?= count($groups) ?></strong> yemek,
                    <strong class="text-dark"><?= count($rows) ?></strong> tarif
                    <?= $filters['q'] !== '' ? '— “' . h($filters['q']) . '” için' : '' ?>
                </div>

                <?php if (!$groups): ?>
                    <div class="card-recipe p-5 text-center">
                        <div class="display-6 mb-2">🍽️</div>
                        <h5>Gösterilecek tarif yok</h5>
                        <p class="text-secondary mb-3">Filtreleri gevşetin ya da yukarıdan yeni bir kaynak site ekleyin.</p>
                        <a class="btn btn-emerald btn-sm" href="?">Filtreleri temizle</a>
                    </div>
                <?php else: ?>
                    <div class="row g-4">
                        <?php foreach ($groups as $g): ?>
                            <div class="col-md-6 col-xl-4">
                                <article class="card-recipe">
                                    <div class="position-relative">
                                        <img class="thumb" loading="lazy" alt="<?= h($g['dish']) ?>"
                                            src="<?= h($g['image'] !== '' ? $g['image'] : 'data:image/gif;base64,R0lGODlhAQABAAAAACH5BAEKAAEALAAAAAABAAEAAAICTAEAOw==') ?>"
                                            onerror="handleImageError(this)">
                                        <span class="badge-count"><i class="fa-solid fa-shop me-1"></i><?= (int) $g['sites'] ?>
                                            site</span>
                                    </div>
                                    <div class="p-3">
                                        <div class="d-flex justify-content-between align-items-start mb-1">
                                            <h5 class="h6 mb-0"><?= h($g['dish']) ?></h5>
                                            <span class="badge text-bg-light border"><?= h($g['category']) ?></span>
                                        </div>
                                        <div class="small text-secondary mb-2">
                                            <?= (int) $g['min'] ?>–<?= (int) $g['max'] ?> kcal · ortalama <?= (int) $g['avg'] ?>
                                            kcal
                                            <?php if ($g['count'] > 1): ?> · <span
                                                    class="text-danger fw-semibold">%<?= (int) $g['spread'] ?>
                                                    fark</span><?php endif; ?>
                                        </div>
                                        <div class="kcal-bar mb-3"></div>
                                        <div class="mb-3">
                                            <?php
                                            $items = $g['items'];
                                            uasort($items, fn($a, $b) => ($b['data_type'] === 'published') <=> ($a['data_type'] === 'published'));
                                            $loopIndex = 0; // Sıra sayacı
                                            foreach (array_slice($items, 0, 3, true) as $i => $it): ?>
                                                <div class="rank-row">
                                                    <span class="text-truncate pe-2">
                                                        <?php if ($loopIndex === 0 && $g['count'] > 1 && $it['data_type'] == 'published'): ?><span
                                                                class="fit-tag me-1">EN
                                                                FİT</span><?php endif; ?>
                                                        <?php if ($it['data_type'] == 'estimated'): ?><span
                                                                class="tahmini-tag me-1">TAHMİNİ</span><?php endif; ?>
                                                        <?= h($it['publisher']) ?>
                                                    </span>
                                                    <span
                                                        class="kcal-tag <?= $loopIndex === 0 && $g['count'] > 1 && $it['data_type'] == 'published' ? 'text-success' : '' ?>"><?= (int) $it['calories'] ?>
                                                        kcal</span>
                                                </div>
                                                <?php $loopIndex++; endforeach; ?>
                                        </div>
                                        <div class="d-flex gap-2">
                                            <button class="btn btn-emerald btn-sm flex-grow-1" data-bs-toggle="modal"
                                                data-bs-target="#m<?= h($g['key']) ?>">
                                                <i class="fa-solid fa-chart-simple me-1"></i> Kalorileri karşılaştır
                                            </button>
                                            <a class="btn btn-outline-secondary btn-sm"
                                                title="Bu yemek için kaynak sitelerde tekrar ara"
                                                href="<?= h(qs(['find' => $g['dish'], 'q' => ''])) ?>"><i
                                                    class="fa-solid fa-magnifying-glass-plus"></i></a>
                                        </div>
                                    </div>
                                </article>
                            </div>

                            <div class="modal fade" id="m<?= h($g['key']) ?>" tabindex="-1" aria-hidden="true">
                                <div class="modal-dialog modal-lg modal-dialog-scrollable">
                                    <div class="modal-content">
                                        <div class="modal-header">
                                            <div>
                                                <h5 class="modal-title mb-0"><?= h($g['dish']) ?></h5>
                                                <small class="text-secondary"><?= (int) $g['min'] ?>–<?= (int) $g['max'] ?> kcal
                                                    · porsiyon başına</small>
                                            </div>
                                            <button type="button" class="btn-close" data-bs-dismiss="modal"
                                                aria-label="Kapat"></button>
                                        </div>
                                        <div class="modal-body">
                                            <div class="table-responsive">
                                                <table class="table table-sm align-middle">
                                                    <thead class="table-light">
                                                        <tr>
                                                            <th>#</th>
                                                            <th>Kaynak site &amp; tarif</th>
                                                            <th class="text-end">kcal</th>
                                                            <th class="text-end">Süre</th>
                                                            <th>Zorluk</th>
                                                            <th></th>
                                                        </tr>
                                                    </thead>
                                                    <tbody>
                                                        <?php
                                                        $items = $g['items'];
                                                        uasort($items, fn($a, $b) => ($b['data_type'] === 'published') <=> ($a['data_type'] === 'published'));
                                                        $loopIndex = 0; // Sıra sayacı
                                                        foreach ($items as $i => $it): ?>
                                                            <tr>
                                                                <td class="text-secondary"><?= $i + 1 ?></td>
                                                                <td>
                                                                    <div class="fw-semibold small"><?= h($it['publisher']) ?>
                                                                        <?php if ($loopIndex === 0 && $g['count'] > 1): ?><span
                                                                                class="fit-tag ms-1">EN FİT</span><?php endif; ?>
                                                                        <?php if ($it['data_type'] == 'estimated'): ?><span
                                                                            class="tahmini-tag me-1">TAHMİNİ</span><?php endif; ?>
                                                                    </div>
                                                                    <div class="text-secondary" style="font-size:.78rem;">
                                                                        <?= h($it['title']) ?>
                                                                    </div>
                                                                </td>
                                                                <td class="text-end">
                                                                    <div class="kcal-tag"><?= (int) $it['calories'] ?></div>
                                                                    <span
                                                                        class="src-tag <?= $it['data_type'] === 'published' ? 'pub' : '' ?>">
                                                                        <?= $it['data_type'] === 'published' ? 'sitenin değeri' : 'hesaplandı' ?></span>
                                                                </td>
                                                                <td class="text-end">
                                                                    <?= ((int) $it['prep_time']) > 0 ? (int) $it['prep_time'] . ' dk' : '—' ?>
                                                                </td>
                                                                <td><?= h($it['difficulty']) ?></td>
                                                                <td class="text-end">
                                                                    <a class="btn btn-emerald btn-sm text-nowrap" target="_blank"
                                                                        rel="noopener nofollow"
                                                                        href="<?= h($it['source_url']) ?>">Tarife git <i
                                                                            class="fa-solid fa-arrow-up-right-from-square ms-1"></i></a>
                                                                </td>
                                                            </tr>
                                                        <?php $loopIndex++; endforeach; ?>
                                                    </tbody>
                                                </table>
                                            </div>
                                            <?php if ($g['count'] > 1): ?>
                                                <p class="small text-secondary">En düşük kalorili tarif, en yükseğe göre
                                                    <strong><?= (int) ($g['max'] - $g['min']) ?> kcal</strong>
                                                    (%<?= (int) $g['spread'] ?>) daha az.
                                                </p>
                                            <?php endif; ?>
                                            <hr>
                                            <h6 class="small text-uppercase text-secondary">Malzemeler —
                                                <?= h($g['items'][0]['publisher']) ?>
                                            </h6>
                                            <div><?php foreach (to_list($g['items'][0]['ingredients']) as $ing): ?><span
                                                        class="ing-tag"><?= h($ing) ?></span><?php endforeach; ?></div>
                                            <p class="small text-secondary mt-3 mb-0">
                                                “sitenin değeri” satırları tarifin yayınlandığı sitenin kendi besin değeri
                                                bilgisidir.
                                                “hesaplandı” satırları o tarifin gerçek malzeme listesinden çıkarılan yaklaşık
                                                değerdir.
                                            </p>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </section>
        </div>
    </main>

    <footer class="text-center text-secondary small py-4">
        Tüm tarifler kaynak sitelerine aittir ve doğrudan onlara bağlanır. Kalori bilgisi tıbbi tavsiye değildir.
    </footer>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        const PLACEHOLDER = "data:image/svg+xml;utf8," + encodeURIComponent(
            '<svg xmlns="http://www.w3.org/2000/svg" width="480" height="340">' +
            '<rect width="100%" height="100%" fill="#e2e8f0"/>' +
            '<text x="50%" y="50%" font-family="sans-serif" font-size="52" fill="#94a3b8" ' +
            'text-anchor="middle" dominant-baseline="central">&#127869;</text></svg>');

        function handleImageError(img) {img.onerror = null; img.src = PLACEHOLDER;}

        (function () {
            const kmin = document.getElementById("kmin"), kmax = document.getElementById("kmax"), tmax = document.getElementById("tmax");
            if (!kmin) return;
            const sync = () => {
                if (parseInt(kmin.value, 10) > parseInt(kmax.value, 10)) {const v = kmin.value; kmin.value = kmax.value; kmax.value = v;}
                document.getElementById("kminOut").textContent = kmin.value;
                document.getElementById("kmaxOut").textContent = kmax.value;
                document.getElementById("tmaxOut").textContent = tmax.value;
            };
            [kmin, kmax, tmax].forEach(el => el.addEventListener("input", sync));
            sync();
        })();


        //Kalori Slider
        document.addEventListener("DOMContentLoaded", function () {
            const inputMin = document.getElementById("kmin");
            const inputMax = document.getElementById("kmax");
            const minOutput = document.getElementById("minOutput");
            const maxOutput = document.getElementById("maxOutput");

            const priceGap = 10;

            function updateSlider(isMinChanged) {
                let minValue = parseInt(inputMin.value);
                let maxValue = parseInt(inputMax.value);

                if (maxValue - minValue < priceGap) {
                    if (isMinChanged) {
                        minValue = maxValue - priceGap;
                        inputMin.value = minValue;
                    } else {
                        maxValue = minValue + priceGap;
                        inputMax.value = maxValue;
                    }
                }

                minOutput.textContent = minValue;
                maxOutput.textContent = maxValue;
            }

            inputMin.addEventListener("input", function () {
                updateSlider(true);
            });

            inputMax.addEventListener("input", function () {
                updateSlider(false);
            });
        });
    </script>
</body>

</html>