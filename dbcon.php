<?php
/**
 * ==========================================================================
 * SRIMS - Database Configuration
 * ==========================================================================
 * Select the required database configuration by commenting/uncommenting the
 * appropriate section below.
 *
 * Available Environments:
 *   1. Cloud (Aiven)
 *   2. SNM-ESPL-PC
 *   3. SNM-ROG-PC
 *   4. SNM-Asus-Laptop (Active)
 * ==========================================================================
 */

/* --------------------------------------------------------------------------
 | Cloud - Aiven
 -------------------------------------------------------------------------- */
/*
define('DB_HOST', 'snehanshu-snehanshu.c.aivencloud.com');
define('DB_PORT', '24795');
define('DB_NAME', 'srims');
define('DB_USER', 'avnadmin');
define('DB_PASS', 'AVNS_e_gHRPusuVBYU6CDkRi');
define('DB_CHARSET', 'utf8mb4');
*/

/* --------------------------------------------------------------------------
 | SNM-ESPL-PC
 * -------------------------------------------------------------------------- */
/*
define('DB_HOST', 'localhost');
define('DB_PORT', '3307');
define('DB_NAME', 'srims');
define('DB_USER', 'root');
define('DB_PASS', 'Puja@1997');
define('DB_CHARSET', 'utf8mb4');
*/

/* --------------------------------------------------------------------------
 | SNM-ROG-PC
 * -------------------------------------------------------------------------- */
/*
define('DB_HOST', 'localhost');
define('DB_PORT', '3307');
define('DB_NAME', 'srims');
define('DB_USER', 'root');
define('DB_PASS', 'Smandal@1997');
define('DB_CHARSET', 'utf8mb4');
*/

/* --------------------------------------------------------------------------
 | SNM-Asus-Laptop (ACTIVE)
 * -------------------------------------------------------------------------- */
/*
define('DB_HOST', 'localhost');
define('DB_PORT', '3307');
define('DB_NAME', 'srims');
define('DB_USER', 'root');
define('DB_PASS', 'Smandal@1997');
define('DB_CHARSET', 'utf8mb4');
*/

/* ==========================================================================
 | PDO Connection
 * ========================================================================== */


$dsn = sprintf(
    'mysql:host=%s;port=%s;dbname=%s;charset=%s',
    DB_HOST,
    DB_PORT,
    DB_NAME,
    DB_CHARSET
);

$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

try {

    $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);

} catch (PDOException $e) {

    http_response_code(500);

    $message = $e->getMessage();

    $networkError =
        stripos($message, 'getaddrinfo') !== false ||
        stripos($message, '[2002]') !== false ||
        stripos($message, 'Connection refused') !== false ||
        stripos($message, 'timed out') !== false ||
        stripos($message, 'Unknown MySQL server host') !== false;

    if ($networkError) {

        $title = 'Unable to Reach Database Server';

        $reasons = [
            'No internet connection (for cloud databases).',
            'Database server is currently offline.',
            'Database host or port is incorrect.',
            'Firewall or network is blocking the connection.'
        ];

    } else {

        $title = 'Database Connection Failed';

        $reasons = [
            'MySQL / MariaDB service is not running.',
            'Incorrect database username or password.',
            'Database does not exist.',
            'Required tables have not been imported.',
            'Database user does not have sufficient privileges.'
        ];
    }

?>
<!DOCTYPE html>
<html lang="en">

<head>

<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">

<title>SRIMS - Database Error</title>

<style>

*{
    margin:0;
    padding:0;
    box-sizing:border-box;
}

body{

    font-family:Segoe UI,Arial,sans-serif;
    background:#f4f6f9;

    display:flex;
    justify-content:center;
    align-items:center;

    min-height:100vh;
    padding:20px;

}

.card{

    width:100%;
    max-width:600px;

    background:#fff;

    border-radius:12px;

    box-shadow:0 12px 35px rgba(0,0,0,.08);

    overflow:hidden;

}

.header{

    background:#dc3545;
    color:#fff;

    padding:22px 28px;

}

.header h2{

    font-size:22px;
    font-weight:600;

}

.content{

    padding:28px;

}

.content p{

    color:#555;
    margin-bottom:18px;
    line-height:1.6;

}

ul{

    margin-left:20px;
    margin-bottom:25px;

}

li{

    margin-bottom:10px;
    color:#444;

}

.info{

    padding:14px;

    border-radius:8px;

    background:#f8f9fa;

    border-left:4px solid #0d6efd;

    color:#555;

    margin-bottom:20px;

}

details{

    margin-top:15px;

}

summary{

    cursor:pointer;

    color:#666;
    font-size:14px;

}

pre{

    margin-top:12px;

    background:#212529;

    color:#f8f9fa;

    padding:15px;

    border-radius:8px;

    overflow:auto;

    font-size:12px;

    white-space:pre-wrap;

}

.footer{

    background:#f8f9fa;

    padding:15px 28px;

    text-align:center;

    color:#777;

    font-size:13px;

}

</style>

</head>

<body>

<div class="card">

    <div class="header">

        <h2>⚠ <?= htmlspecialchars($title) ?></h2>

    </div>

    <div class="content">

        <p>
            SRIMS was unable to establish a connection to the database.
            Please verify the following:
        </p>

        <ul>

            <?php foreach ($reasons as $reason): ?>

                <li><?= htmlspecialchars($reason) ?></li>

            <?php endforeach; ?>

        </ul>

        <div class="info">

            Once the issue has been resolved, simply refresh this page.

        </div>

        <details>

            <summary>Technical Details</summary>

            <pre><?= htmlspecialchars($message) ?></pre>

        </details>

    </div>

    <div class="footer">

        SRIMS Database Connection Manager

    </div>

</div>

</body>

</html>

<?php
exit;
}