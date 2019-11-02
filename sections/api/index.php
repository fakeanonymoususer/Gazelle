<?php

function getClassObject($name, $db, $cache, $config) {
    $name = "Gazelle\\API\\".str_replace("_", "", ucwords($name, "_"));
    return new $name($db, $cache, $config);
}

$available = array(
    'generate_invite',
    'user',
    'wiki',
    'forum',
    'request',
    'artist',
    'collage',
    'torrent'
);

if (in_array($_GET['action'], $available)) {
    $config = array('Categories' => $Categories, 'CollageCats' => $CollageCats,
        'ReleaseTypes' => $ReleaseTypes, 'Debug' => $Debug);
    $class = getClassObject($_GET['action'], $DB, $Cache, $Twig, $config);
} else {
    json_error('invalid action');
}

if (empty($_GET['aid']) || empty($_GET['token'])) {
    json_error('invalid parameters');
}

$app_id = intval($_GET['aid']);
$token = $_GET['token'];

$app = $Cache->get_value("api_apps_{$app_id}");
if (!is_array($app)) {
    $DB->prepared_query("
        SELECT Token, Name
        FROM api_applications
        WHERE ID = ?
        LIMIT 1", $app_id);
    if ($DB->record_count() === 0) {
        json_error('invalid app');
    }
    $app = $DB->to_array(false, MYSQLI_ASSOC);
    $Cache->cache_value("api_apps_{$app_id}", $app, 0);
}
$app = $app[0];

if ($app['Token'] !== $token) {
    json_error('invalid token');
}

$response = $class->run();
print(json_encode(array('status' => 200, 'response' => $response), JSON_UNESCAPED_SLASHES));
//$Debug->profile();
