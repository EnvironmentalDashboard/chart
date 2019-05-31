<?php
$host = getenv('MYSQL_HOST');
$dbname = getenv('MYSQL_DB');
$user = getenv('MYSQL_USER');
$pass = getenv('MYSQL_PASS');
$dsn = sprintf('mysql:host=%s;dbname=%s;charset=utf8;port=3306', $host, $dbname);
try {
    $db = new PDO($dsn, $user, $pass);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $db->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
} catch (PDOException $e) {
    die($e->getMessage());
}

$user_id = 1; // Default to Oberlin
$subdomain = explode('.', $_SERVER['HTTP_HOST'])[0];
if (strlen($subdomain) > 0) {
    $stmt = $db->prepare('SELECT id FROM users WHERE slug = ?');
    $stmt->execute(array($subdomain));
    if ($stmt->rowCount() === 1) {
        $user_id = intval($stmt->fetchColumn());
    }  
}
