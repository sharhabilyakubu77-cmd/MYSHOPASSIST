<?php
/*
 * Sale reversal now lives in reports.php (POST + CSRF token + Administrator/Manager check).
 *
 * This file is kept only so old bookmarks or links don't break. It performs NO deletion
 * and NO stock changes. You can safely delete this file once nothing links to it.
 */
declare(strict_types=1);

session_start();

if (empty($_SESSION['username'])) {
    header('Location: login.php');
    exit;
}

header('Location: reports.php');
exit;