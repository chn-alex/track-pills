<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
date_default_timezone_set('Europe/Kyiv');

$pdo = new PDO(
    'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
    DB_USER,
    DB_PASS,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);

$update = json_decode(file_get_contents('php://input'), true) ?: [];
$message = $update['message'] ?? null;
$callback = $update['callback_query'] ?? null;

function telegram(string $method, array $data): array {
    $url = 'https://api.telegram.org/bot' . BOT_TOKEN . '/' . $method;
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $data,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 15,
    ]);
    $result = curl_exec($ch);
    curl_close($ch);
    return json_decode((string)$result, true) ?: [];
}

function sendText(int|string $chatId, string $text, ?array $keyboard = null): void {
    $data = ['chat_id' => $chatId, 'text' => $text, 'parse_mode' => 'HTML'];
    if ($keyboard !== null) $data['reply_markup'] = json_encode($keyboard, JSON_UNESCAPED_UNICODE);
    telegram('sendMessage', $data);
}

function answerCallback(string $id, string $text = ''): void {
    telegram('answerCallbackQuery', ['callback_query_id' => $id, 'text' => $text]);
}

function mainKeyboard(): array {
    return ['keyboard' => [
        [['text' => '💊 Відмітити прийом'], ['text' => '📅 Календар']],
        [['text' => '⚙️ Мої таблетки'], ['text' => '❓ Допомога']]
    ], 'resize_keyboard' => true];
}

function ensureUser(PDO $pdo, int $telegramId, int $chatId, string $name): void {
    $stmt = $pdo->prepare('INSERT INTO users (telegram_id, chat_id, display_name) VALUES (?, ?, ?)
        ON DUPLICATE KEY UPDATE chat_id = VALUES(chat_id), display_name = VALUES(display_name)');
    $stmt->execute([$telegramId, $chatId, $name]);
}

function getTablets(PDO $pdo, int $telegramId): array {
    $stmt = $pdo->prepare('SELECT id, name FROM tablets WHERE telegram_id = ? AND is_active = 1 ORDER BY sort_order, id');
    $stmt->execute([$telegramId]);
    return $stmt->fetchAll();
}

function todayKeyboard(PDO $pdo, int $telegramId): array {
    $tablets = getTablets($pdo, $telegramId);
    $date = date('Y-m-d');
    $stmt = $pdo->prepare('SELECT tablet_id FROM intake_logs WHERE telegram_id = ? AND intake_date = ?');
    $stmt->execute([$telegramId, $date]);
    $done = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    $rows = [];
    foreach ($tablets as $tablet) {
        $id = (int)$tablet['id'];
        $checked = in_array($id, $done, true);
        $rows[] = [[
            'text' => ($checked ? '✅ ' : '⬜️ ') . $tablet['name'],
            'callback_data' => 'toggle:' . $id
        ]];
    }
    $rows[] = [['text' => '🔄 Оновити', 'callback_data' => 'refresh']];
    return ['inline_keyboard' => $rows];
}

function showToday(PDO $pdo, int $chatId, int $telegramId, bool $edit = false, ?int $messageId = null): void {
    $tablets = getTablets($pdo, $telegramId);
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM intake_logs WHERE telegram_id = ? AND intake_date = ?');
    $stmt->execute([$telegramId, date('Y-m-d')]);
    $count = (int)$stmt->fetchColumn();
    $total = count($tablets);
    $text = "💊 <b>Прийом таблеток</b>\n" . date('d.m.Y') . "\n\nВідмічено: {$count} із {$total}\n\nНатисни на назву таблетки, щоб відмітити або скасувати прийом.";
    $keyboard = todayKeyboard($pdo, $telegramId);
    if ($edit && $messageId) {
        telegram('editMessageText', ['chat_id' => $chatId, 'message_id' => $messageId, 'text' => $text, 'parse_mode' => 'HTML', 'reply_markup' => json_encode($keyboard, JSON_UNESCAPED_UNICODE)]);
    } else {
        sendText($chatId, $text, $keyboard);
    }
}

function showTablets(PDO $pdo, int $chatId, int $telegramId): void {
    $tablets = getTablets($pdo, $telegramId);
    $text = "⚙️ <b>Мої таблетки</b>\n\n";
    if (!$tablets) $text .= "Список поки порожній.\n";
    foreach ($tablets as $t) $text .= "• " . htmlspecialchars($t['name'], ENT_QUOTES, 'UTF-8') . " (ID " . (int)$t['id'] . ")\n";
    $text .= "\nЩоб додати: <code>/add Назва таблетки</code>\nЩоб видалити: <code>/delete ID</code>\nНаприклад: <code>/add Вітамін D</code>";
    sendText($chatId, $text, mainKeyboard());
}

function showCalendar(PDO $pdo, int $chatId, int $telegramId, string $month): void {
    if (!preg_match('/^\d{4}-\d{2}$/', $month) || !checkdate((int)substr($month, 5, 2), 1, (int)substr($month, 0, 4))) {
        $month = date('Y-m');
    }
    $start = $month . '-01';
    $end = date('Y-m-t', strtotime($start));
    $tablets = getTablets($pdo, $telegramId);
    $stmt = $pdo->prepare('SELECT l.intake_date, l.tablet_id, t.name FROM intake_logs l JOIN tablets t ON t.id = l.tablet_id WHERE l.telegram_id = ? AND l.intake_date BETWEEN ? AND ? ORDER BY l.intake_date, t.sort_order, t.id');
    $stmt->execute([$telegramId, $start, $end]);
    $logs = [];
    foreach ($stmt->fetchAll() as $row) $logs[$row['intake_date']][] = $row['name'];
    $text = "📅 <b>Календар прийому — " . date('m.Y', strtotime($start)) . "</b>\n";
    $text .= "Позначка 💊 означає, що того дня було відмічено хоча б один прийом.\n\n";
    $days = (int)date('t', strtotime($start));
    for ($day = 1; $day <= $days; $day++) {
        $date = sprintf('%s-%02d', $month, $day);
        $names = $logs[$date] ?? [];
        $text .= sprintf('%02d', $day) . ': ' . ($names ? '💊 ' . count($names) . '/' . count($tablets) . ' — ' . implode(', ', $names) : '—') . "\n";
    }
    $prev = date('Y-m', strtotime($start . ' -1 month'));
    $next = date('Y-m', strtotime($start . ' +1 month'));
    $keyboard = ['inline_keyboard' => [[
        ['text' => '⬅️ Попередній', 'callback_data' => 'month:' . $prev],
        ['text' => 'Наступний ➡️', 'callback_data' => 'month:' . $next]
    ]]];
    sendText($chatId, $text, $keyboard);
}

if ($message) {
    $chatId = (int)$message['chat']['id'];
    $telegramId = (int)$message['from']['id'];
    $name = trim(($message['from']['first_name'] ?? '') . ' ' . ($message['from']['last_name'] ?? ''));
    ensureUser($pdo, $telegramId, $chatId, $name);
    $text = trim($message['text'] ?? '');

    if (preg_match('/^\/start\b/', $text)) {
        if (count(getTablets($pdo, $telegramId)) === 0) {
            $stmt = $pdo->prepare('INSERT INTO tablets (telegram_id, name, sort_order) VALUES (?, ?, 1), (?, ?, 2)');
            $stmt->execute([$telegramId, 'Таблетка 1', $telegramId, 'Таблетка 2']);
        }
        sendText($chatId, "Привіт! Я допоможу не забувати відмічати прийом таблеток.\n\nКнопки нижче дозволяють відмічати прийом і переглядати календар. Список таблеток можна змінювати командами /add та /delete.", mainKeyboard());
        showToday($pdo, $chatId, $telegramId);
    } elseif (preg_match('/^\/add\s+(.+)/u', $text, $m)) {
        $name = trim($m[1]);
        if (mb_strlen($name) > 80) $name = mb_substr($name, 0, 80);
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM tablets WHERE telegram_id = ? AND is_active = 1');
        $stmt->execute([$telegramId]);
        if ((int)$stmt->fetchColumn() >= 20) {
            sendText($chatId, 'Можна додати максимум 20 таблеток.', mainKeyboard());
        } else {
            $stmt = $pdo->prepare('INSERT INTO tablets (telegram_id, name, sort_order) VALUES (?, ?, 99)');
            $stmt->execute([$telegramId, $name]);
            sendText($chatId, '✅ Додано: ' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8'), mainKeyboard());
            showToday($pdo, $chatId, $telegramId);
        }
    } elseif (preg_match('/^\/delete\s+(\d+)/', $text, $m)) {
        $stmt = $pdo->prepare('UPDATE tablets SET is_active = 0 WHERE id = ? AND telegram_id = ?');
        $stmt->execute([(int)$m[1], $telegramId]);
        sendText($chatId, $stmt->rowCount() ? '🗑 Таблетку прибрано зі списку. Історія збережена.' : 'Не знайшов активну таблетку з таким ID.', mainKeyboard());
        showTablets($pdo, $chatId, $telegramId);
    } elseif ($text === '💊 Відмітити прийом') {
        showToday($pdo, $chatId, $telegramId);
    } elseif ($text === '📅 Календар' || preg_match('/^\/calendar(?:\s+(\d{4}-\d{2}))?/', $text, $m)) {
        $month = $m[1] ?? date('Y-m');
        showCalendar($pdo, $chatId, $telegramId, $month);
    } elseif ($text === '⚙️ Мої таблетки' || $text === '/tablets') {
        showTablets($pdo, $chatId, $telegramId);
    } else {
        sendText($chatId, "Команди:\n/add Назва — додати таблетку\n/delete ID — прибрати таблетку\n/calendar — календар\n/tablets — список таблеток", mainKeyboard());
    }
}

if ($callback) {
    $callbackId = $callback['id'];
    $chatId = (int)$callback['message']['chat']['id'];
    $messageId = (int)$callback['message']['message_id'];
    $telegramId = (int)$callback['from']['id'];
    $data = $callback['data'] ?? '';
    ensureUser($pdo, $telegramId, $chatId, trim(($callback['from']['first_name'] ?? '') . ' ' . ($callback['from']['last_name'] ?? '')));

    if (preg_match('/^toggle:(\d+)$/', $data, $m)) {
        $tabletId = (int)$m[1];
        $stmt = $pdo->prepare('SELECT id FROM tablets WHERE id = ? AND telegram_id = ? AND is_active = 1');
        $stmt->execute([$tabletId, $telegramId]);
        if ($stmt->fetch()) {
            $stmt = $pdo->prepare('SELECT id FROM intake_logs WHERE telegram_id = ? AND tablet_id = ? AND intake_date = ?');
            $stmt->execute([$telegramId, $tabletId, date('Y-m-d')]);
            if ($stmt->fetch()) {
                $stmt = $pdo->prepare('DELETE FROM intake_logs WHERE telegram_id = ? AND tablet_id = ? AND intake_date = ?');
                $stmt->execute([$telegramId, $tabletId, date('Y-m-d')]);
                answerCallback($callbackId, 'Відмітку скасовано');
            } else {
                $stmt = $pdo->prepare('INSERT INTO intake_logs (telegram_id, tablet_id, intake_date, taken_at) VALUES (?, ?, ?, NOW())');
                $stmt->execute([$telegramId, $tabletId, date('Y-m-d')]);
                answerCallback($callbackId, 'Прийом відмічено');
            }
        } else answerCallback($callbackId, 'Таблетку не знайдено');
        showToday($pdo, $chatId, $telegramId, true, $messageId);
    } elseif ($data === 'refresh') {
        answerCallback($callbackId);
        showToday($pdo, $chatId, $telegramId, true, $messageId);
    } elseif (preg_match('/^month:(\d{4}-\d{2})$/', $data, $m)) {
        answerCallback($callbackId);
        // Replace the existing calendar message rather than sending another one.
        $month = $m[1];
        $start = $month . '-01';
        $end = date('Y-m-t', strtotime($start));
        $tablets = getTablets($pdo, $telegramId);
        $stmt = $pdo->prepare('SELECT l.intake_date, l.tablet_id, t.name FROM intake_logs l JOIN tablets t ON t.id = l.tablet_id WHERE l.telegram_id = ? AND l.intake_date BETWEEN ? AND ? ORDER BY l.intake_date, t.sort_order, t.id');
        $stmt->execute([$telegramId, $start, $end]);
        $logs = [];
        foreach ($stmt->fetchAll() as $row) $logs[$row['intake_date']][] = $row['name'];
        $text = "📅 <b>Календар прийому — " . date('m.Y', strtotime($start)) . "</b>\nПозначка 💊 означає відмічений прийом.\n\n";
        for ($day = 1, $days = (int)date('t', strtotime($start)); $day <= $days; $day++) {
            $date = sprintf('%s-%02d', $month, $day);
            $names = $logs[$date] ?? [];
            $text .= sprintf('%02d', $day) . ': ' . ($names ? '💊 ' . count($names) . '/' . count($tablets) . ' — ' . implode(', ', $names) : '—') . "\n";
        }
        $prev = date('Y-m', strtotime($start . ' -1 month'));
        $next = date('Y-m', strtotime($start . ' +1 month'));
        $keyboard = ['inline_keyboard' => [[
            ['text' => '⬅️ Попередній', 'callback_data' => 'month:' . $prev],
            ['text' => 'Наступний ➡️', 'callback_data' => 'month:' . $next]
        ]]];
        telegram('editMessageText', ['chat_id' => $chatId, 'message_id' => $messageId, 'text' => $text, 'parse_mode' => 'HTML', 'reply_markup' => json_encode($keyboard, JSON_UNESCAPED_UNICODE)]);
    }
}
