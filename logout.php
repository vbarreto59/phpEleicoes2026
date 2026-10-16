<?php
// logout.php — Encerra a sessão.
session_start();
date_default_timezone_set('America/Sao_Paulo');
session_unset();
session_destroy();
header('Location: login.php');
exit;