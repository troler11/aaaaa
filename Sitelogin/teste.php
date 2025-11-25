<?php
$hash = '$2y$10$hknQ7ZgaLPiMBkkednxLIuCq38qPkYCsCicEreHb7QLRFdwS2B6pW';
$senha = 'senha123';
if (password_verify($senha, $hash)) {
    echo "✅ Senha correta!";
} else {
    echo "❌ Senha incorreta!";
}
?>
