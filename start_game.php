<?php
include 'config.php';
header('Content-Type: application/json; charset=utf-8');

// Set default timezone
date_default_timezone_set('Europe/Kiev');

// Function to return JSON error response
function returnError($message) {
    echo json_encode(["error" => $message]);
    exit;
}

// Debug logging
$log_file = fopen("start_game_log.txt", "a");
fwrite($log_file, "--------------------\n");
fwrite($log_file, date("Y-m-d H:i:s") . "\n");
fwrite($log_file, "POST data: " . print_r($_POST, true) . "\n");

// Check if player_id is provided
if (!isset($_POST['player_id'])) {
    fwrite($log_file, "Error: No player ID provided\n");
    fclose($log_file);
    returnError("No player ID provided");
}

$player_id = intval($_POST['player_id']);
fwrite($log_file, "Processing player_id: $player_id\n");

// Verify player exists
$player_check = mysqli_query($conn, "SELECT id, status FROM players WHERE id = $player_id");
if (!$player_check) {
    fwrite($log_file, "Database error: " . mysqli_error($conn) . "\n");
    fclose($log_file);
    returnError("Database error when checking player");
}

if (mysqli_num_rows($player_check) == 0) {
    fwrite($log_file, "Error: Player ID does not exist\n");
    fclose($log_file);
    returnError("Player ID does not exist");
}

$player_data = mysqli_fetch_assoc($player_check);
fwrite($log_file, "Player current status: " . $player_data['status'] . "\n");

// Handle force_start parameter
$force_start = isset($_POST['force_start']) && $_POST['force_start'] === "true";
if ($force_start) {
    fwrite($log_file, "Force start requested\n");
    
    // Get the game the player is in
    $player_game_check = mysqli_query($conn, "SELECT game_id FROM game_players WHERE player_id = $player_id");
    if (!$player_game_check) {
        fwrite($log_file, "Database error: " . mysqli_error($conn) . "\n");
        fclose($log_file);
        returnError("Database error when checking player game");
    }
    
    if (mysqli_num_rows($player_game_check) > 0) {
        $player_game = mysqli_fetch_assoc($player_game_check);
        $game_id = $player_game['game_id'];
        
        fwrite($log_file, "Forcing start for game_id: $game_id\n");
        
        // Update game status to started
        $update_game = mysqli_query($conn, "UPDATE games SET status = 'started', start_time = NOW() WHERE id = $game_id");
        if (!$update_game) {
            fwrite($log_file, "Error updating game: " . mysqli_error($conn) . "\n");
            fclose($log_file);
            returnError("Could not update game status");
        }
        
        // Update all players in this game to 'playing' status
        $update_players = mysqli_query($conn, "UPDATE players SET status = 'playing' WHERE id IN (SELECT player_id FROM game_players WHERE game_id = $game_id)");
        if (!$update_players) {
            fwrite($log_file, "Error updating player statuses to playing: " . mysqli_error($conn) . "\n");
        }
        
        fwrite($log_file, "Game successfully forced to start\n");
        fclose($log_file);
        
        echo json_encode([
            "start" => true,
            "game_id" => $game_id,
            "force_started" => true
        ]);
        exit;
    } else {
        fwrite($log_file, "Error: Player not in any game to force start\n");
        fclose($log_file);
        returnError("Player not in any game to force start");
    }
}

// Check if player is already in a game
$player_game_check = mysqli_query($conn, "SELECT gp.game_id, g.status, g.start_time 
                                         FROM game_players gp 
                                         JOIN games g ON gp.game_id = g.id 
                                         WHERE gp.player_id = $player_id");

if (!$player_game_check) {
    fwrite($log_file, "Database error checking game: " . mysqli_error($conn) . "\n");
    fclose($log_file);
    returnError("Database error when checking player game status");
}



// Player is already in a game
if (mysqli_num_rows($player_game_check) > 0) {
    $player_game = mysqli_fetch_assoc($player_game_check);
    $game_id = $player_game['game_id'];
    $game_status = $player_game['status'];
    $start_time = $player_game['start_time'];
    
    fwrite($log_file, "Player already in game_id: $game_id with status: $game_status\n");
    
    // Update player status to ensure it's correct
    $update_player_status = mysqli_query($conn, "UPDATE players SET status='waiting' WHERE id=$player_id");
    if (!$update_player_status) {
        fwrite($log_file, "Warning: Could not update player status: " . mysqli_error($conn) . "\n");
    }
    
    // Find other waiting players to add to the current game
    addWaitingPlayersToGame($conn, $game_id, $log_file);
    
    // Count players
    $res = mysqli_query($conn, "SELECT COUNT(*) as total FROM game_players WHERE game_id = $game_id");
    if (!$res) {
        fwrite($log_file, "Error counting players: " . mysqli_error($conn) . "\n");
        fclose($log_file);
        returnError("Error counting players");
    }
    
    $data = mysqli_fetch_assoc($res);
    $playerCount = $data['total'];
    fwrite($log_file, "Current player count: $playerCount\n");
    
    // Get player list
    $player_list = getPlayerList($conn, $game_id, $log_file);
    if ($player_list === false) {
        fclose($log_file);
        returnError("Error retrieving player list");
    }
    
    // KEY REQUIREMENT: If player count is 1, set start_time to null
    if ($playerCount == 1) {
        $update = mysqli_query($conn, "UPDATE games SET start_time=NULL WHERE id = $game_id");
        if (!$update) {
            fwrite($log_file, "Error clearing start time for single player: " . mysqli_error($conn) . "\n");
        } else {
            fwrite($log_file, "Cleared start time for game with single player\n");
            $start_time = null;
        }
    }
    // If player count increases to 2+, set start_time if not already set
    elseif ($playerCount >= 2 && $start_time === null) {
        $start_time = date("Y-m-d H:i:s", strtotime("+3 minutes"));
        $update = mysqli_query($conn, "UPDATE games SET start_time='$start_time' WHERE id = $game_id");
        if (!$update) {
            fwrite($log_file, "Error setting initial start time: " . mysqli_error($conn) . "\n");
        } else {
            fwrite($log_file, "Set initial start time to $start_time for game with $playerCount players\n");
        }
    }
    
    // Check game status and start time
    $current_time = date("Y-m-d H:i:s");
    $should_start = ($game_status === 'started') || 
                   ($playerCount >= 5) || 
                   ($start_time !== null && strtotime($start_time) <= strtotime($current_time));
    
    fwrite($log_file, "Should game start? " . ($should_start ? "Yes" : "No") . "\n");
    fwrite($log_file, "Game status: " . $game_status . "\n");
    if ($start_time !== null) {
        fwrite($log_file, "Start time: " . $start_time . " (current: " . $current_time . ")\n");
    }
    
    // If game should start
    if ($should_start) {
        // Update game status if not already started
        if ($game_status !== 'started') {
            $update = mysqli_query($conn, "UPDATE games SET status='started' WHERE id = $game_id");
            if (!$update) {
                fwrite($log_file, "Error updating game status: " . mysqli_error($conn) . "\n");
                fclose($log_file);
                returnError("Could not update game status");
            }
            fwrite($log_file, "Updated game status to 'started'\n");

            

            // Update all players to 'playing' status
            $update_players = mysqli_query($conn, "UPDATE players SET status = 'playing' WHERE id IN (SELECT player_id FROM game_players WHERE game_id = $game_id)");
            if (!$update_players) {
                fwrite($log_file, "Error updating player statuses to playing: " . mysqli_error($conn) . "\n");
            }
        }
        
        fwrite($log_file, "Returning start=true\n");
        fclose($log_file);
        
        echo json_encode([
            "start" => true,
            "game_id" => $game_id,
            "players" => $playerCount,
            "start_time" => $start_time,
            "player_list" => $player_list
        ]);
    } else {
        // If we have 5 players, update start time to 5 seconds from now
        if ($playerCount == 5 && ($start_time === null || strtotime($start_time) > strtotime("+5 seconds"))) {
            $new_start_time = date("Y-m-d H:i:s", strtotime("+5 seconds"));
            $update = mysqli_query($conn, "UPDATE games SET start_time='$new_start_time' WHERE id = $game_id");
            if (!$update) {
                fwrite($log_file, "Error updating start time for full game: " . mysqli_error($conn) . "\n");
            } else {
                fwrite($log_file, "Game is full! Updated start time to $new_start_time\n");
                $start_time = $new_start_time;
            }
        }
        
        fwrite($log_file, "Returning start=false\n");
        fclose($log_file);
        
        echo json_encode([
            "start" => false,
            "game_id" => $game_id,
            "players" => $playerCount,
            "start_time" => $start_time,
            "player_list" => $player_list
        ]);
    }
    exit;
}

// PLAYER NOT IN GAME - Find or create a game and add them

// 1. Get or create a game with "waiting" status
$result = mysqli_query($conn, "SELECT * FROM games WHERE status = 'waiting' LIMIT 1");
if (!$result) {
    fwrite($log_file, "Database error: " . mysqli_error($conn) . "\n");
    fclose($log_file);
    returnError("Database error when querying games");
}

if (mysqli_num_rows($result) > 0) {
    $game = mysqli_fetch_assoc($result);
    $game_id = $game['id'];
    $start_time = $game['start_time'];
    fwrite($log_file, "Found existing waiting game: $game_id\n");
} else {
    $create_game = mysqli_query($conn, "INSERT INTO games (status) VALUES ('waiting')");
    if (!$create_game) {
        fwrite($log_file, "Error creating game: " . mysqli_error($conn) . "\n");
        fclose($log_file);
        returnError("Could not create new game");
    }
    $game_id = mysqli_insert_id($conn);
    fwrite($log_file, "Created new game: $game_id\n");
    
    // Initialize default game
    $start_time = null;
}

// 2. Add current player to the game
$add_player = mysqli_query($conn, "INSERT INTO game_players (game_id, player_id) VALUES ($game_id, $player_id)");
if (!$add_player) {
    fwrite($log_file, "Error adding player to game: " . mysqli_error($conn) . "\n");
    fclose($log_file);
    returnError("Could not add player to game");
}
fwrite($log_file, "Added player $player_id to game $game_id\n");

// Update player status to waiting
$update_status = mysqli_query($conn, "UPDATE players SET status='waiting' WHERE id=$player_id");
if (!$update_status) {
    fwrite($log_file, "Error updating player status: " . mysqli_error($conn) . "\n");
    fclose($log_file);
    returnError("Could not update player status");
}
fwrite($log_file, "Updated player status to 'waiting'\n");

// 3. Add other waiting players
addWaitingPlayersToGame($conn, $game_id, $log_file);

// 4. Count players
$res = mysqli_query($conn, "SELECT COUNT(*) as total FROM game_players WHERE game_id = $game_id");
if (!$res) {
    fwrite($log_file, "Error counting players: " . mysqli_error($conn) . "\n");
    fclose($log_file);
    returnError("Error counting players");
}

$data = mysqli_fetch_assoc($res);
$playerCount = $data['total'];
fwrite($log_file, "Final player count: $playerCount\n");



// Get player list
$player_list = getPlayerList($conn, $game_id, $log_file);
if ($player_list === false) {
    fclose($log_file);
    returnError("Error retrieving player list");
}

// 5. Game start logic

// KEY REQUIREMENT: If player count is 1, ensure start_time is NULL
if ($playerCount == 1) {
    if ($start_time !== null) {
        $update = mysqli_query($conn, "UPDATE games SET start_time=NULL WHERE id = $game_id");
        if (!$update) {
            fwrite($log_file, "Error clearing start time for single player: " . mysqli_error($conn) . "\n");
        } else {
            $start_time = null;
            fwrite($log_file, "Cleared start time for single player game\n");
        }
    }
    
    fwrite($log_file, "Returning game info for single player without start_time\n");
    fclose($log_file);
    
    echo json_encode([
        "start" => false,
        "game_id" => $game_id,
        "players" => $playerCount,
        "player_list" => $player_list
    ]);
    exit;
}

// If there are 2+ players
if ($playerCount >= 2) {
    // If start time is not set, set it to 3 minutes from now
    if ($start_time === null) {
        $start_time = date("Y-m-d H:i:s", strtotime("+3 minutes"));
        $update = mysqli_query($conn, "UPDATE games SET start_time='$start_time' WHERE id = $game_id");
        if (!$update) {
            fwrite($log_file, "Error updating start time: " . mysqli_error($conn) . "\n");
            fclose($log_file);
            returnError("Could not update game start time");
        }
        fwrite($log_file, "Set game start time to $start_time\n");
    }
    
    // If there are 5 players, update start time to 5 seconds from now
    if ($playerCount >= 5) {
        $start_time = date("Y-m-d H:i:s", strtotime("+5 seconds"));
        $update = mysqli_query($conn, "UPDATE games SET start_time='$start_time' WHERE id = $game_id");
        if (!$update) {
            fwrite($log_file, "Error updating start time for full game: " . mysqli_error($conn) . "\n");
            fclose($log_file);
            returnError("Could not update game start time");
        }
        fwrite($log_file, "Game is full! Updated start time to $start_time\n");
    }

    // Check if it's time to start the game
    $current_time = date("Y-m-d H:i:s");
    if (strtotime($start_time) <= strtotime($current_time)) {
        $update = mysqli_query($conn, "UPDATE games SET status='started' WHERE id = $game_id");
        if (!$update) {
            fwrite($log_file, "Error updating game status to started: " . mysqli_error($conn) . "\n");
        } else {
            fwrite($log_file, "Time to start! Game status set to 'started'\n");
            
            // Update all players to 'playing' status
            $update_players = mysqli_query($conn, "UPDATE players SET status = 'playing' WHERE id IN (SELECT player_id FROM game_players WHERE game_id = $game_id)");
            if (!$update_players) {
                fwrite($log_file, "Error updating player statuses to playing: " . mysqli_error($conn) . "\n");
            }
            
            fwrite($log_file, "Returning start=true\n");
            fclose($log_file);
            
            echo json_encode([
                "start" => true,
                "game_id" => $game_id,
                "players" => $playerCount,
                "start_time" => $start_time,
                "player_list" => $player_list
            ]);
            exit;
        }
    }

    fwrite($log_file, "Returning game info with start_time\n");
    fclose($log_file);
    
    echo json_encode([
        "start" => false,
        "game_id" => $game_id,
        "players" => $playerCount,
        "start_time" => $start_time,
        "player_list" => $player_list
    ]);
}



// Function to get player list for a game
function getPlayerList($conn, $game_id, $log_file) {
    $player_list_query = mysqli_query($conn, "
        SELECT p.id, p.username 
        FROM players p 
        JOIN game_players gp ON p.id = gp.player_id 
        WHERE gp.game_id = $game_id
    ");
    
    if (!$player_list_query) {
        fwrite($log_file, "Error retrieving player list: " . mysqli_error($conn) . "\n");
        return false;
    }
    
    $player_list = [];
    while ($player = mysqli_fetch_assoc($player_list_query)) {
        $player_list[] = [
            'id' => intval($player['id']),
            'username' => $player['username']
        ];
    }
    
    fwrite($log_file, "Player list retrieved with " . count($player_list) . " players\n");
    return $player_list;
}

// Function to add waiting players to a game
function addWaitingPlayersToGame($conn, $game_id, $log_file) {
    // Find players with 'waiting' status who aren't in any game
    $find_waiting_players = mysqli_query($conn, "
        SELECT p.id 
        FROM players p 
        LEFT JOIN game_players gp ON p.id = gp.player_id 
        WHERE p.status = 'waiting' 
        AND gp.player_id IS NULL 
        LIMIT 4
    ");
    
    if (!$find_waiting_players) {
        fwrite($log_file, "Error searching for waiting players: " . mysqli_error($conn) . "\n");
        return false;
    }
    
    $found_count = mysqli_num_rows($find_waiting_players);
    fwrite($log_file, "Found $found_count waiting players to add\n");
    
    $added_count = 0;
    
    while ($waiting_player = mysqli_fetch_assoc($find_waiting_players)) {
        $waiting_id = $waiting_player['id'];
        
        // Additional check to avoid duplication
        $double_check = mysqli_query($conn, "SELECT 1 FROM game_players WHERE player_id = $waiting_id");
        if (mysqli_num_rows($double_check) > 0) {
            fwrite($log_file, "Player $waiting_id is already in a game, skipping\n");
            continue;
        }
        
        // Add player to current game
        $add_waiting = mysqli_query($conn, "INSERT INTO game_players (game_id, player_id) VALUES ($game_id, $waiting_id)");
        if (!$add_waiting) {
            fwrite($log_file, "Error adding waiting player $waiting_id: " . mysqli_error($conn) . "\n");
            continue;
        }
        
        $added_count++;
        fwrite($log_file, "Added waiting player $waiting_id to game $game_id\n");
    }
    
    fwrite($log_file, "Successfully added $added_count new waiting players to game\n");
    return $added_count;
}



// Additional function to check and fix games without start time
function checkAndFixGamesWithoutStartTime($conn, $log_file) {
    // Get games with 'waiting' status, no start time, and 2+ players
    $query = mysqli_query($conn, "
        SELECT g.id, COUNT(gp.player_id) as player_count 
        FROM games g 
        JOIN game_players gp ON g.id = gp.game_id 
        WHERE g.status = 'waiting' AND g.start_time IS NULL 
        GROUP BY g.id 
        HAVING COUNT(gp.player_id) >= 2
    ");
    
    if (!$query) {
        fwrite($log_file, "Error checking games without start time: " . mysqli_error($conn) . "\n");
        return;
    }
    
    $fixed_count = 0;
    
    while ($game = mysqli_fetch_assoc($query)) {
        $game_id = $game['id'];
        $player_count = $game['player_count'];
        
        $start_time = date("Y-m-d H:i:s", strtotime("+3 minutes"));
        $update = mysqli_query($conn, "UPDATE games SET start_time='$start_time' WHERE id = $game_id");
        
        if ($update) {
            $fixed_count++;
            fwrite($log_file, "Fixed game $game_id with $player_count players - set start time to $start_time\n");
        }
    }
    
    if ($fixed_count > 0) {
        fwrite($log_file, "Fixed $fixed_count games that had players but no start time\n");
    }
    
    // Also fix games with only 1 player that have a start time (should be NULL)
    $single_player_query = mysqli_query($conn, "
        SELECT g.id 
        FROM games g 
        JOIN game_players gp ON g.id = gp.game_id 
        WHERE g.status = 'waiting' AND g.start_time IS NOT NULL 
        GROUP BY g.id 
        HAVING COUNT(gp.player_id) = 1
    ");
    
    if (!$single_player_query) {
        fwrite($log_file, "Error checking single player games with start time: " . mysqli_error($conn) . "\n");
        return;
    }
    
    $single_fixed_count = 0;
    
    while ($game = mysqli_fetch_assoc($single_player_query)) {
        $game_id = $game['id'];
        
        $update = mysqli_query($conn, "UPDATE games SET start_time=NULL WHERE id = $game_id");
        
        if ($update) {
            $single_fixed_count++;
            fwrite($log_file, "Fixed single player game $game_id - cleared start time\n");
        }
    }
    
    if ($single_fixed_count > 0) {
        fwrite($log_file, "Fixed $single_fixed_count single player games that incorrectly had start times\n");
    }
}

// Call the fix function with each request
checkAndFixGamesWithoutStartTime($conn, $log_file);
?>
