<?php
// --- CONFIGURAÇÃO DOS MENUS ---
// Defina aqui todos os menus possíveis, seus ícones e links.
// A 'chave' (ex: 'dashboard', 'rotas') deve ser igual ao que salvamos no banco de dados.
$menu_itens = [
    'dashboard'  => ['label' => 'Dashboard',  'icon' => 'bi-speedometer2',      'link' => '/'],
    'rotas'      => ['label' => 'Rotas',      'icon' => 'bi-map',               'link' => '#'],
    'veiculos'   => ['label' => 'Veículos',   'icon' => 'bi-bus-front',         'link' => '#'],
    'motoristas' => ['label' => 'Motoristas', 'icon' => 'bi-person-vcard',      'link' => '#'],
    'escala'     => ['label' => 'Escala',     'icon' => 'bi-calendar-week',      'link' => 'escala.php'], // Ícone repetido conforme seu código
    'relatorios' => ['label' => 'Power BI', 'icon' => 'bi-calendar-week', 'link' => 'relatorio.php'],
    'usuarios' => ['label' => 'Usuários', 'icon' => 'bi-people-fill me-2', 'link' => 'admin.php'],
];

// Pega as permissões da sessão (Array de strings, ex: ['rotas', 'veiculos'])
// Nota: Se for Admin, nem precisa checar isso, pois liberamos tudo.
$permissoes_usuario = [];
if (isset($_SESSION['user_menus'])) {
    $permissoes_usuario = json_decode($_SESSION['user_menus'], true);
    if (!is_array($permissoes_usuario)) $permissoes_usuario = [];
}

// Define qual página está ativa para pintar o botão (opcional, lógica simples baseada no link)
$pagina_atual = basename($_SERVER['PHP_SELF']); 
?>
