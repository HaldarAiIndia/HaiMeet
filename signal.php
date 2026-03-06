<?php
/**
 * HaiMeet — PHP/SQLite Signaling Server
 * Replaces Firebase Realtime Database for WebRTC signaling.
 *
 * Endpoints (all JSON):
 *   POST action=join_queue      — add/refresh self in queue
 *   POST action=find_match      — claim a compatible peer from queue
 *   POST action=send_offer      — caller uploads SDP offer
 *   POST action=send_answer     — callee uploads SDP answer
 *   POST action=send_ice        — push an ICE candidate
 *   POST action=leave           — remove self from queue / delete call
 *   GET  action=poll            — caller & callee: fetch answer + ICE
 *   GET  action=poll_callee     — callee waiting in queue: check for offer
 */

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache');
header('X-Content-Type-Options: nosniff');

/* ── Database ─────────────────────────────────────────────────── */
$dbFile = __DIR__ . '/haimeet_signal.db';
try {
    $pdo = new PDO('sqlite:' . $dbFile);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec("PRAGMA journal_mode=WAL");
    $pdo->exec("PRAGMA synchronous=NORMAL");
    $pdo->exec("PRAGMA busy_timeout=3000");
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'DB init failed: ' . $e->getMessage()]);
    exit;
}

/* ── Schema ───────────────────────────────────────────────────── */
$pdo->exec("
    CREATE TABLE IF NOT EXISTS queue (
        id       TEXT    PRIMARY KEY,
        name     TEXT    NOT NULL DEFAULT 'Stranger',
        gender   TEXT    NOT NULL DEFAULT 'male',
        interest TEXT    NOT NULL DEFAULT 'any',
        ts       INTEGER NOT NULL
    );
    CREATE TABLE IF NOT EXISTS calls (
        id           TEXT    PRIMARY KEY,
        caller_id    TEXT    NOT NULL,
        callee_id    TEXT    NOT NULL,
        offer        TEXT,
        answer       TEXT,
        caller_name  TEXT,
        callee_name  TEXT,
        ts           INTEGER NOT NULL
    );
    CREATE TABLE IF NOT EXISTS ice (
        id        INTEGER PRIMARY KEY AUTOINCREMENT,
        call_id   TEXT    NOT NULL,
        from_uid  TEXT    NOT NULL,
        cand      TEXT    NOT NULL,
        ts        INTEGER NOT NULL
    );
    CREATE INDEX IF NOT EXISTS idx_ice_call ON ice(call_id, from_uid, id);
    CREATE INDEX IF NOT EXISTS idx_calls_callee ON calls(callee_id);
");

/* ── Periodic cleanup (≈2 % of requests) ─────────────────────── */
if (rand(1, 50) === 1) {
    $now = time();
    $pdo->exec("DELETE FROM queue WHERE ts < " . ($now - 25));
    $pdo->exec("DELETE FROM calls WHERE ts < " . ($now - 120));
    $pdo->exec("DELETE FROM ice   WHERE ts < " . ($now - 300));
}

/* ── Input parsing ────────────────────────────────────────────── */
$method = $_SERVER['REQUEST_METHOD'];
$data   = [];

if ($method === 'POST') {
    $raw  = file_get_contents('php://input');
    $data = json_decode($raw, true) ?? [];
    $action = $data['action'] ?? '';
} else {
    $action = $_GET['action'] ?? '';
}

/* ── Helpers ──────────────────────────────────────────────────── */
function respond(array $payload): void {
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

function clean(mixed $v, int $max = 64): string {
    return mb_substr(strip_tags(trim((string)$v)), 0, $max);
}

function validGender(mixed $v): string {
    return in_array($v, ['male', 'female'], true) ? (string)$v : 'male';
}

function validInterest(mixed $v): string {
    return in_array($v, ['any', 'male', 'female'], true) ? (string)$v : 'any';
}

/* ── Router ───────────────────────────────────────────────────── */
switch ($action) {

    /* ────────────────────────────────────────────────────────────
       join_queue — insert or refresh the caller's queue slot.
       Called once on enter and every ~8 s as a heartbeat.
    ──────────────────────────────────────────────────────────── */
    case 'join_queue': {
        $id       = clean($data['id'] ?? '');
        $name     = clean($data['name'] ?? 'Stranger', 32);
        $gender   = validGender($data['gender'] ?? '');
        $interest = validInterest($data['interest'] ?? '');

        if (!$id) respond(['error' => 'Missing id']);

        $stmt = $pdo->prepare(
            "INSERT OR REPLACE INTO queue (id, name, gender, interest, ts) VALUES (?,?,?,?,?)"
        );
        $stmt->execute([$id, $name, $gender, $interest, time()]);
        respond(['ok' => true]);
    }

    /* ────────────────────────────────────────────────────────────
       find_match — atomically claim one compatible peer.
       Returns {match: {id,name,gender,interest}} or {match: null}.
    ──────────────────────────────────────────────────────────── */
    case 'find_match': {
        $id       = clean($data['id'] ?? '');
        $gender   = validGender($data['gender'] ?? '');
        $interest = validInterest($data['interest'] ?? '');

        if (!$id) respond(['error' => 'Missing id']);

        $fresh = time() - 20;   // only consider entries younger than 20 s

        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare(
                "SELECT * FROM queue WHERE id != ? AND ts >= ? ORDER BY ts ASC LIMIT 30"
            );
            $stmt->execute([$id, $fresh]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $match = null;
            foreach ($rows as $r) {
                $iOk = ($interest === 'any' || $interest === $r['gender']);
                $tOk = ($r['interest'] === 'any' || $r['interest'] === $gender);
                if ($iOk && $tOk) { $match = $r; break; }
            }

            if ($match) {
                $del = $pdo->prepare("DELETE FROM queue WHERE id = ?");
                $del->execute([$match['id']]);
                if ($del->rowCount() > 0) {
                    $pdo->commit();
                    respond(['match' => $match]);
                } else {
                    // Another caller claimed it first — retry
                    $pdo->rollBack();
                    respond(['match' => null]);
                }
            } else {
                $pdo->commit();
                respond(['match' => null]);
            }
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            respond(['error' => $e->getMessage()]);
        }
    }

    /* ────────────────────────────────────────────────────────────
       send_offer — caller stores SDP offer for the callee.
       call_id = callee's user-id (mirrors Firebase convention).
    ──────────────────────────────────────────────────────────── */
    case 'send_offer': {
        $callId     = clean($data['call_id']     ?? '');
        $callerId   = clean($data['caller_id']   ?? '');
        $calleeId   = clean($data['callee_id']   ?? '');
        $offer      = json_encode($data['offer']  ?? []);
        $callerName = clean($data['caller_name'] ?? 'Stranger', 32);

        if (!$callId || !$callerId || !$calleeId) respond(['error' => 'Missing fields']);

        $stmt = $pdo->prepare("
            INSERT OR REPLACE INTO calls
                (id, caller_id, callee_id, offer, caller_name, ts)
            VALUES (?,?,?,?,?,?)
        ");
        $stmt->execute([$callId, $callerId, $calleeId, $offer, $callerName, time()]);
        respond(['ok' => true]);
    }

    /* ────────────────────────────────────────────────────────────
       send_answer — callee stores SDP answer.
    ──────────────────────────────────────────────────────────── */
    case 'send_answer': {
        $callId    = clean($data['call_id']    ?? '');
        $answer    = json_encode($data['answer'] ?? []);
        $calleeName = clean($data['callee_name'] ?? 'Stranger', 32);

        if (!$callId) respond(['error' => 'Missing call_id']);

        $stmt = $pdo->prepare("UPDATE calls SET answer = ?, callee_name = ? WHERE id = ?");
        $stmt->execute([$answer, $calleeName, $callId]);
        respond(['ok' => true]);
    }

    /* ────────────────────────────────────────────────────────────
       send_ice — store one ICE candidate from either peer.
    ──────────────────────────────────────────────────────────── */
    case 'send_ice': {
        $callId  = clean($data['call_id'] ?? '');
        $fromUid = clean($data['from']    ?? '');
        $cand    = json_encode($data['candidate'] ?? []);

        if (!$callId || !$fromUid) respond(['error' => 'Missing fields']);

        $stmt = $pdo->prepare(
            "INSERT INTO ice (call_id, from_uid, cand, ts) VALUES (?,?,?,?)"
        );
        $stmt->execute([$callId, $fromUid, $cand, time()]);
        respond(['ok' => true]);
    }

    /* ────────────────────────────────────────────────────────────
       poll — both peers call this ~700 ms to pick up:
           • SDP answer (caller only)
           • ICE candidates from the remote peer
       Pass last_ice=0 initially; update to the highest id returned.
    ──────────────────────────────────────────────────────────── */
    case 'poll': {
        $callId  = clean($_GET['call_id']  ?? '');
        $userId  = clean($_GET['user_id']  ?? '');
        $lastIce = intval($_GET['last_ice'] ?? 0);

        if (!$callId || !$userId) respond(['error' => 'Missing params']);

        // Fetch call row
        $stmt = $pdo->prepare("SELECT * FROM calls WHERE id = ?");
        $stmt->execute([$callId]);
        $call = $stmt->fetch(PDO::FETCH_ASSOC);

        // Fetch ICE from the remote peer, newer than what we've seen
        $stmt = $pdo->prepare("
            SELECT id, cand FROM ice
            WHERE call_id = ? AND from_uid != ? AND id > ?
            ORDER BY id ASC LIMIT 60
        ");
        $stmt->execute([$callId, $userId, $lastIce]);
        $iceRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        respond([
            'call' => $call ? [
                'answer'      => $call['answer']      ? json_decode($call['answer'],      true) : null,
                'caller_name' => $call['caller_name'],
                'callee_name' => $call['callee_name'],
            ] : null,
            'ice' => array_map(fn($r) => [
                'id'        => (int)$r['id'],
                'candidate' => json_decode($r['cand'], true),
            ], $iceRows),
        ]);
    }

    /* ────────────────────────────────────────────────────────────
       poll_callee — callee polls this while sitting in the queue,
       waiting for the caller to deliver an offer.
       (call_id = myId because caller sets id = callee's user-id)
    ──────────────────────────────────────────────────────────── */
    case 'poll_callee': {
        $userId = clean($_GET['user_id'] ?? '');
        if (!$userId) respond(['error' => 'Missing user_id']);

        $stmt = $pdo->prepare(
            "SELECT * FROM calls WHERE id = ? ORDER BY ts DESC LIMIT 1"
        );
        $stmt->execute([$userId]);
        $call = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($call && $call['offer']) {
            respond([
                'found'       => true,
                'call_id'     => $call['id'],
                'caller_id'   => $call['caller_id'],
                'offer'       => json_decode($call['offer'], true),
                'caller_name' => $call['caller_name'],
            ]);
        } else {
            respond(['found' => false]);
        }
    }

    /* ────────────────────────────────────────────────────────────
       leave — called on hangup / page unload.
       Removes queue entry and/or deletes call + ICE rows.
    ──────────────────────────────────────────────────────────── */
    case 'leave': {
        $id     = clean($data['id']      ?? '');
        $callId = clean($data['call_id'] ?? '');

        if ($id)     $pdo->prepare("DELETE FROM queue WHERE id = ?")->execute([$id]);
        if ($callId) {
            $pdo->prepare("DELETE FROM calls WHERE id = ?")->execute([$callId]);
            $pdo->prepare("DELETE FROM ice   WHERE call_id = ?")->execute([$callId]);
        }
        respond(['ok' => true]);
    }

    /* ────────────────────────────────────────────────────────────
       Default
    ──────────────────────────────────────────────────────────── */
    default:
        http_response_code(400);
        respond(['error' => 'Unknown action: ' . $action]);
}