<?php
session_start(); // Inicia a sessão
session_unset(); // Limpa todas as variáveis da sessão
session_destroy(); // Destrói a sessão

// Redireciona para a página de login
header('Location: login.php');
exit;
?>