<?php
/**
 * Seed: create 30 test users, subscribe them to all activities,
 *       add sector preferences for servos, and set activities as
 *       ready for drawing (inscription end dates in the past).
 *
 * Run: php backend/seed_test_data.php
 */

$host = '127.0.0.1';
$port = 3307;
$db   = 'acampamento';
$user = 'root';
$pass = 'irineu';

$pdo = new PDO("mysql:host=$host;port=$port;dbname=$db;charset=utf8mb4", $user, $pass, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);

echo "Connected to database.\n";

// ─── Helper: generate a valid CPF ───────────────────────────────────────────
function gerarCpf(): string {
    do {
        $n = [];
        for ($i = 0; $i < 9; $i++) $n[] = rand(0, 9);

        $sum = 0;
        for ($i = 0; $i < 9; $i++) $sum += $n[$i] * (10 - $i);
        $rem = (10 * $sum) % 11;
        $n[] = $rem >= 10 ? 0 : $rem;

        $sum = 0;
        for ($i = 0; $i < 10; $i++) $sum += $n[$i] * (11 - $i);
        $rem = (10 * $sum) % 11;
        $n[] = $rem >= 10 ? 0 : $rem;

        $cpf = implode('', $n);
    } while (preg_match('/(\d)\1{10}/', $cpf));
    return $cpf;
}

// ─── User data ───────────────────────────────────────────────────────────────
// marital_status_id: 1=Solteiro(a), 2=Casado(a)
// sex: M / F
// Couples share spouse names but are separate users
$users = [
    // Couples (marital_status_id=2) — 5 couples = 10 people
    ['name' => 'João Pedro Alves',     'sex' => 'M', 'ms' => 2, 'bday' => '1992-03-15', 'email_prefix' => 'joao.alves'],
    ['name' => 'Ana Paula Alves',      'sex' => 'F', 'ms' => 2, 'bday' => '1994-07-22', 'email_prefix' => 'ana.alves'],
    ['name' => 'Carlos Eduardo Lima',  'sex' => 'M', 'ms' => 2, 'bday' => '1988-11-08', 'email_prefix' => 'carlos.lima'],
    ['name' => 'Fernanda Lima',        'sex' => 'F', 'ms' => 2, 'bday' => '1990-05-30', 'email_prefix' => 'fernanda.lima'],
    ['name' => 'Rodrigo Martins',      'sex' => 'M', 'ms' => 2, 'bday' => '1985-09-12', 'email_prefix' => 'rodrigo.martins'],
    ['name' => 'Camila Martins',       'sex' => 'F', 'ms' => 2, 'bday' => '1987-02-18', 'email_prefix' => 'camila.martins'],
    ['name' => 'Thiago Costa',         'sex' => 'M', 'ms' => 2, 'bday' => '1991-06-25', 'email_prefix' => 'thiago.costa'],
    ['name' => 'Juliana Costa',        'sex' => 'F', 'ms' => 2, 'bday' => '1993-01-14', 'email_prefix' => 'juliana.costa'],
    ['name' => 'André Santos',         'sex' => 'M', 'ms' => 2, 'bday' => '1984-12-03', 'email_prefix' => 'andre.santos'],
    ['name' => 'Mariana Santos',       'sex' => 'F', 'ms' => 2, 'bday' => '1986-08-27', 'email_prefix' => 'mariana.santos'],

    // Single males (marital_status_id=1) — 10 men
    ['name' => 'Lucas Oliveira',       'sex' => 'M', 'ms' => 1, 'bday' => '1999-04-10', 'email_prefix' => 'lucas.oliveira'],
    ['name' => 'Gabriel Ferreira',     'sex' => 'M', 'ms' => 1, 'bday' => '2001-08-16', 'email_prefix' => 'gabriel.ferreira'],
    ['name' => 'Mateus Rodrigues',     'sex' => 'M', 'ms' => 1, 'bday' => '2000-02-28', 'email_prefix' => 'mateus.rodrigues'],
    ['name' => 'Felipe Gomes',         'sex' => 'M', 'ms' => 1, 'bday' => '1998-10-05', 'email_prefix' => 'felipe.gomes'],
    ['name' => 'Bruno Pereira',        'sex' => 'M', 'ms' => 1, 'bday' => '1997-07-19', 'email_prefix' => 'bruno.pereira'],
    ['name' => 'Eduardo Barbosa',      'sex' => 'M', 'ms' => 1, 'bday' => '2002-03-08', 'email_prefix' => 'eduardo.barbosa'],
    ['name' => 'Rafael Carvalho',      'sex' => 'M', 'ms' => 1, 'bday' => '1996-11-22', 'email_prefix' => 'rafael.carvalho'],
    ['name' => 'Daniel Ribeiro',       'sex' => 'M', 'ms' => 1, 'bday' => '2003-06-14', 'email_prefix' => 'daniel.ribeiro'],
    ['name' => 'Henrique Souza',       'sex' => 'M', 'ms' => 1, 'bday' => '1995-09-01', 'email_prefix' => 'henrique.souza'],
    ['name' => 'Leonardo Nascimento',  'sex' => 'M', 'ms' => 1, 'bday' => '2000-12-30', 'email_prefix' => 'leonardo.nascimento'],

    // Single females (marital_status_id=1) — 10 women
    ['name' => 'Beatriz Moreira',      'sex' => 'F', 'ms' => 1, 'bday' => '2001-01-17', 'email_prefix' => 'beatriz.moreira'],
    ['name' => 'Larissa Teixeira',     'sex' => 'F', 'ms' => 1, 'bday' => '1999-05-23', 'email_prefix' => 'larissa.teixeira'],
    ['name' => 'Natalia Freitas',      'sex' => 'F', 'ms' => 1, 'bday' => '2002-09-11', 'email_prefix' => 'natalia.freitas'],
    ['name' => 'Amanda Cardoso',       'sex' => 'F', 'ms' => 1, 'bday' => '1998-03-04', 'email_prefix' => 'amanda.cardoso'],
    ['name' => 'Leticia Vieira',       'sex' => 'F', 'ms' => 1, 'bday' => '2000-07-29', 'email_prefix' => 'leticia.vieira'],
    ['name' => 'Paula Mendes',         'sex' => 'F', 'ms' => 1, 'bday' => '1997-11-16', 'email_prefix' => 'paula.mendes'],
    ['name' => 'Raquel Pinto',         'sex' => 'F', 'ms' => 1, 'bday' => '2003-02-07', 'email_prefix' => 'raquel.pinto'],
    ['name' => 'Priscila Araujo',      'sex' => 'F', 'ms' => 1, 'bday' => '1996-06-20', 'email_prefix' => 'priscila.araujo'],
    ['name' => 'Vanessa Cruz',         'sex' => 'F', 'ms' => 1, 'bday' => '2001-10-03', 'email_prefix' => 'vanessa.cruz'],
    ['name' => 'Tatiane Monteiro',     'sex' => 'F', 'ms' => 1, 'bday' => '1999-08-25', 'email_prefix' => 'tatiane.monteiro'],
];

// ─── Insert users ─────────────────────────────────────────────────────────────
$passwordHash = password_hash('senha123', PASSWORD_BCRYPT);
$now          = date('Y-m-d H:i:s');
$insertedIds  = [];

$stmtUser = $pdo->prepare("
    INSERT INTO users (cpf, name, birthday, sex, phone, email, is_counselor, is_admin, password, marital_status_id, created, modified)
    VALUES (?, ?, ?, ?, ?, ?, 0, 0, ?, ?, ?, ?)
    ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id)
");

foreach ($users as $u) {
    $cpf   = gerarCpf();
    $email = $u['email_prefix'] . '@teste.com';
    $phone = '65' . rand(90000000, 99999999);

    // Check email not duplicated
    $check = $pdo->prepare("SELECT id FROM users WHERE email=? AND deleted IS NULL");
    $check->execute([$email]);
    if ($check->fetch()) {
        echo "  Skipping {$u['name']} (email exists)\n";
        continue;
    }

    $stmtUser->execute([$cpf, $u['name'], $u['bday'], $u['sex'], $phone, $email, $passwordHash, $u['ms'], $now, $now]);
    $id = (int)$pdo->lastInsertId();
    $insertedIds[] = $id;
    echo "  Created user: {$u['name']} (id=$id, cpf=$cpf)\n";
}

echo "\nCreated " . count($insertedIds) . " users.\n\n";

if (empty($insertedIds)) {
    echo "No new users inserted. Fetching existing test users...\n";
    $st = $pdo->query("SELECT id FROM users WHERE email LIKE '%@teste.com' AND deleted IS NULL");
    $insertedIds = $st->fetchAll(PDO::FETCH_COLUMN);
    echo "Found " . count($insertedIds) . " existing test users.\n";
}

// ─── Get all activities ───────────────────────────────────────────────────────
$activities = $pdo->query("SELECT id, name FROM activities WHERE deleted IS NULL")->fetchAll(PDO::FETCH_ASSOC);
echo "\nActivities found: " . count($activities) . "\n";

// ─── Get ACS records per activity ────────────────────────────────────────────
// For servo inscriptions, we need ACS IDs per activity
$acsPerActivity = [];
$stAcs = $pdo->query("SELECT id, activity_id FROM activities_categories_sectors WHERE deleted IS NULL");
foreach ($stAcs->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $acsPerActivity[$row['activity_id']][] = (int)$row['id'];
}

// ─── Subscribe users to all activities ───────────────────────────────────────
echo "\nSubscribing users to activities...\n";

$stmtPR = $pdo->prepare("
    INSERT INTO pre_registrations
        (subscription_type, is_fee_paid, user_id, activity_id, is_quitter, created, modified)
    VALUES (?, 0, ?, ?, 0, ?, ?)
    ON DUPLICATE KEY UPDATE id=id
");

// Unique constraint check (user_id + activity_id unique per subscription_type)
$stmtCheck = $pdo->prepare("
    SELECT id FROM pre_registrations
    WHERE user_id=? AND activity_id=? AND subscription_type=? AND deleted IS NULL
");

$stmtAcsu = $pdo->prepare("
    INSERT INTO activities_categories_sectors_users
        (user_id, activities_categories_sector_id, is_coordinator)
    VALUES (?, ?, 0)
    ON DUPLICATE KEY UPDATE id=id
");

$stmtCheckAcsu = $pdo->prepare("
    SELECT id FROM activities_categories_sectors_users
    WHERE user_id=? AND activities_categories_sector_id=?
");

foreach ($activities as $act) {
    $actId = (int)$act['id'];
    $acsIds = $acsPerActivity[$actId] ?? [];

    echo "\n  Activity: {$act['name']} (id=$actId), ACS sectors: " . count($acsIds) . "\n";

    foreach ($insertedIds as $i => $userId) {
        // First 20 users → Campista, next 10 → Servo
        $tipo = ($i < 20) ? 'Campista' : 'Servo';

        // Check if already subscribed
        $stmtCheck->execute([$userId, $actId, $tipo]);
        if ($stmtCheck->fetch()) continue;

        $stmtPR->execute([$tipo, $userId, $actId, $now, $now]);

        // For servants, also add sector preferences (up to 2 sectors)
        if ($tipo === 'Servo' && !empty($acsIds)) {
            // Distribute sectors: each servo gets 1 or 2 sectors
            $sectorsForUser = array_slice($acsIds, ($i - 20) % count($acsIds), 2);
            // Wrap around if needed
            if (count($sectorsForUser) < 2 && count($acsIds) >= 2) {
                $sectorsForUser = [$acsIds[$i % count($acsIds)], $acsIds[($i + 1) % count($acsIds)]];
            }
            foreach ($sectorsForUser as $acsId) {
                $stmtCheckAcsu->execute([$userId, $acsId]);
                if (!$stmtCheckAcsu->fetch()) {
                    $stmtAcsu->execute([$userId, $acsId]);
                }
            }
        }
    }
    echo "    Subscriptions done.\n";
}

// ─── Update activity dates so they're ready for drawing ──────────────────────
echo "\nUpdating activity dates to make them ready for drawing...\n";

$pdo->exec("
    UPDATE activities
    SET
        participant_registration_start_date = '2026-05-01 08:00:00',
        participant_registration_end_date   = '2026-06-20 23:59:00',
        servant_registration_start_date     = '2026-04-25 08:00:00',
        servant_registration_end_date       = '2026-06-15 23:59:00',
        start_date = CASE
            WHEN id = 7 THEN '2026-07-15 08:00:00'
            WHEN id = 8 THEN '2026-08-10 08:00:00'
            ELSE start_date
        END
    WHERE deleted IS NULL AND camping_id IS NOT NULL
");

echo "  Activities updated.\n";

// Update camping raffle vacancies to match total_vacancies / 2 per category
$camping_updates = $pdo->query("
    SELECT c.id, a.total_vacancies
    FROM campings c
    JOIN activities a ON a.camping_id = c.id
    WHERE a.deleted IS NULL
")->fetchAll(PDO::FETCH_ASSOC);

foreach ($camping_updates as $row) {
    $total  = (int)$row['total_vacancies'];
    $homem  = (int)floor($total * 0.4);
    $mulher = (int)floor($total * 0.4);
    $casado = (int)floor(($total - $homem - $mulher) / 2);

    $pdo->prepare("
        UPDATE campings SET
            raffle_man_vacancies    = ?,
            raffle_woman_vacancies  = ?,
            raffle_couple_vacancies = ?
        WHERE id = ?
    ")->execute([$homem, $mulher, $casado, $row['id']]);

    echo "  Camping id={$row['id']}: {$homem}H + {$mulher}M + {$casado}C (total=$total)\n";
}

echo "\n✓ Seed complete!\n";
echo "Test user password: senha123\n";
