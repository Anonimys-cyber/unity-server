<?php
include 'config.php';

// Отримуємо параметри
$game_id = $_REQUEST['game_id'] ?? null;

if (!$game_id || !is_numeric($game_id)) {
    http_response_code(400);
    echo "Invalid or missing game_id.";
    exit;
}

// Запит для отримання всіх гравців цієї гри
$query = "
    SELECT gp.player_id, p.username, gp.kronus, gp.lyrion, gp.mystara, gp.eclipsia, gp.fiora, gp.score
    FROM game_players gp
    JOIN players p ON gp.player_id = p.id
    WHERE gp.game_id = ?
";
$stmt = $conn->prepare($query);
$stmt->bind_param("i", $game_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    http_response_code(404);
    echo "No players found for this game.";
    exit;
}

// Отримуємо всі записи гравців
$players_data = [];
while ($row = $result->fetch_assoc()) {
    $players_data[] = [
        'player_id' => $row['player_id'],
        'username' => $row['username'],
        'kronus' => $row['kronus'],
        'lyrion' => $row['lyrion'],
        'mystara' => $row['mystara'],
        'eclipsia' => $row['eclipsia'],
        'fiora' => $row['fiora'],
        'score' => $row['score'],
    ];
}

// Відправляємо дані у форматі JSON
echo json_encode($players_data);

$conn->close();
?>
