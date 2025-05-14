<?php
include 'config.php';
header('Content-Type: application/json; charset=utf-8');

// Логування запиту
file_put_contents("log.txt", print_r($_POST, true), FILE_APPEND);

// Перевірка player_id і game_id
$game_id = $_POST['game_id'] ?? null;
$player_id = $_POST['player_id'] ?? null;


if (!$player_id || !$game_id) {
    die(json_encode(["error" => "Missing player_id or game_id"]));
}

// Список планет
$fields = ['kronus', 'lyrion', 'mystara', 'eclipsia', 'fiora'];
$updates = [];

foreach ($fields as $field) {
    if (isset($_POST[$field])) {
        $value = mysqli_real_escape_string($conn, $_POST[$field]);
        $updates[] = "$field = '$value'";
    }
}

if (count($updates) === 0) {
    die(json_encode(["error" => "No drone data to update."]));
}

$player_id = mysqli_real_escape_string($conn, $player_id);
$game_id = mysqli_real_escape_string($conn, $game_id);
$setClause = implode(', ', $updates);

$query = "UPDATE game_players SET $setClause WHERE player_id = '$player_id' AND game_id = '$game_id'";
$result = mysqli_query($conn, $query);

if ($result) {
    echo json_encode(["success" => "Drone distribution updated successfully."]);
} else {
    echo json_encode(["error" => "Update failed.", "details" => mysqli_error($conn)]);
}

mysqli_close($conn);
?>
