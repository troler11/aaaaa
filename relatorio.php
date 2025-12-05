<?php
// --- CONFIGURAÇÃO BÁSICA ---
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once 'config.php'; 
require_once 'menus.php'; 
require_once 'includes/page_logic.php';

// Verificação de Segurança
if (!isset($_SESSION['user_logged_in']) || $_SESSION['user_logged_in'] !== true) { 
    header("Location: login.php"); 
    exit; 
}

verificarPermissaoPagina('relatorio');
$pagina_atual = basename($_SERVER['PHP_SELF']);
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Viação Mimo - Dashboard BI</title>
    
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">

    <style>
    body { background-color: #f8f9fa; font-family: 'Segoe UI', sans-serif; overflow: hidden; /* Evita scroll duplo na página */ }
    
    /* Layout Sidebar */
    .sidebar { background-color: #0b1f3a; color: #fff; height: 100vh; width: 250px; position: fixed; z-index: 1000; transition: all .3s ease; overflow-y: auto; }
    .sidebar a { color: #d1d5db; display: flex; align-items: center; padding: 14px 20px; text-decoration: none; border-left: 4px solid transparent; font-weight: 500; white-space: nowrap; overflow: hidden; }
    .sidebar a i { min-width: 30px; font-size: 1.1rem; }
    .sidebar a.active, .sidebar a:hover { background-color: #1b2e52; color: #fff; border-left-color: #0d6efd; }
    .sidebar .logo-container img { transition: all .3s; max-width: 160px; }
    
    /* Sidebar Recolhida */
    .sidebar.toggled { width: 80px; }
    .sidebar.toggled .logo-container img { max-width: 50px; }
    .sidebar.toggled a span { display: none; }
    .sidebar.toggled a { justify-content: center; padding: 14px 0; }
    .sidebar.toggled a i { margin-right: 0 !important; }
    
    /* Área de Conteúdo */
    .content { margin-left: 250px; padding: 20px; height: 100vh; display: flex; flex-direction: column; transition: all .3s ease; }
    .content.toggled { margin-left: 80px; }

    /* Container do Power BI */
    .bi-container {
        flex-grow: 1;
        background: #fff;
        border-radius: 12px;
        box-shadow: 0 4px 15px rgba(0,0,0,0.05);
        overflow: hidden;
        position: relative;
    }

    .bi-container iframe {
        position: absolute;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        border: none;
    }
    </style>
</head>
<body>

<div class="sidebar d-flex flex-column" id="sidebar">
    <div class="text-center py-4 bg-dark bg-opacity-25 logo-container">
        <img src="https://viacaomimo.com.br/wp-content/uploads/2023/07/Background-12-1.png" alt="Logo">
    </div>
    <?php 
    if (isset($menu_itens) && is_array($menu_itens)) {
        foreach ($menu_itens as $chave => $item): 
            $is_admin = ($_SESSION['user_role'] ?? '') === 'admin';
            $tem_permissao = in_array($chave, $permissoes_usuario ?? []);
            if ($is_admin || $tem_permissao):
                $classe_active = ($pagina_atual == $item['link'] || ($chave == 'escala' && $pagina_atual == 'escala.php')) ? 'active' : '';
    ?>
        <a href="<?php echo $item['link']; ?>" class="<?php echo $classe_active; ?>" title="<?php echo $item['label']; ?>">
            <i class="bi <?php echo $item['icon']; ?> me-2"></i><span><?php echo $item['label']; ?></span>
        </a>
    <?php endif; endforeach; } ?>
    
    <a href="logout.php" class="mt-auto text-danger border-top border-secondary" title="Sair">
        <i class="bi bi-box-arrow-right me-2"></i><span>Sair</span>
    </a>
</div>

<div class="content" id="content">
    
    <div class="d-flex justify-content-between align-items-center mb-3">
        <div class="d-flex align-items-center gap-3">
            <button class="btn btn-outline-dark border-0 shadow-sm" id="btnToggleMenu">
                <i class="bi bi-list fs-5"></i>
            </button>
            <div>
                <h4 class="fw-bold text-dark mb-0">Indicadores</h4>
                <p class="text-muted small mb-0">Visualização Power BI</p>
            </div>
        </div>
        
        <button class="btn btn-white border shadow-sm" onclick="toggleFullScreen()" title="Modo Tela Cheia">
            <i class="bi bi-arrows-fullscreen"></i>
        </button>
    </div>

    <div class="bi-container">
        <iframe 
            title="Dashboard Viação Mimo" 
            src="https://app.powerbi.com/view?r=eyJrIjoiMTA0YjQwNDUtNTBmYy00ZGVmLThhYzAtYzQ0ZTQyYmQ4ODY4IiwidCI6IjdlNDE1NmFiLWI3ZTgtNDZlMC1hOWNiLWE0MDgzYTRmNjdmNSJ9" 
            allowFullScreen="true">
        </iframe>
    </div>

</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

<script>
    // Lógica Toggle Menu
    const btnToggle = document.getElementById('btnToggleMenu');
    if (btnToggle) {
        btnToggle.addEventListener('click', function() {
            document.getElementById('sidebar').classList.toggle('toggled');
            document.getElementById('content').classList.toggle('toggled');
        });
    }

    // Lógica Tela Cheia
    function toggleFullScreen() {
        if (!document.fullscreenElement) {
            document.documentElement.requestFullscreen().catch(err => {
                console.log(`Erro ao ativar tela cheia: ${err.message}`);
            });
        } else {
            if (document.exitFullscreen) {
                document.exitFullscreen();
            }
        }
    }
</script>

</body>
</html>
