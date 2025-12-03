<?php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Carrega configurações e lógica
require_once 'config.php'; 
require_once 'includes/page_logic.php';

// --- SEGURANÇA ---
if (!isset($_SESSION['user_logged_in']) || $_SESSION['user_logged_in'] !== true) {
    header("Location: login.php");
    exit;
}
/**
 * -------------------------------------------------------------------------
 * DASHBOARD DE FROTA - VERSÃO FINAL (CACHE DINÂMICO POR DATA)
 * -------------------------------------------------------------------------
 */

// 1. CONFIGURAÇÕES
// -------------------------------------------------------------------------
define('GOOGLE_SCRIPT_URL', 'https://script.google.com/macros/s/AKfycbxpJjRQ0KhIQtHA36CD_cugZyQD1GrftfIahwqxV9Nqxx1jnF5T2bt0tQgNM0kWfRArrQ/exec'); 

define('CACHE_TIME', 60); // 60 segundos

// 2. DATA E TIMEZONE
// -------------------------------------------------------------------------
date_default_timezone_set('America/Sao_Paulo');
$data_filtro = $_GET['data'] ?? date('d/m/Y'); 

// --- LÓGICA DE CACHE DINÂMICO (CORREÇÃO AQUI) ---
// Transforma "02/12/2025" em "cache_02-12-2025.json"
// Isso impede que o cache de um dia apareça no outro
$nome_arquivo_cache = 'cache_frota_' . str_replace('/', '-', $data_filtro) . '.json';

// Refresh Forçado (Apaga apenas o cache da data atual)
if (isset($_GET['force_refresh']) && $_GET['force_refresh'] == '1') {
    if (file_exists($nome_arquivo_cache)) @unlink($nome_arquivo_cache); 
}
// ------------------------------------------------

// 3. FUNÇÃO DE LEITURA (JSON)
// -------------------------------------------------------------------------
function getDadosJson($scriptUrl, $data, $cacheFile, $cacheTime) {
    // Verifica se o cache existe e é recente
    if (!file_exists($cacheFile) || (time() - filemtime($cacheFile) > $cacheTime)) {
        
        $urlLoad = $scriptUrl . "?action=read&data=" . urlencode($data);
        
        // Contexto para evitar erros de bloqueio simples
        $options = ["http" => ["method" => "GET", "header" => "User-Agent: PHP"]];
        $context = stream_context_create($options);
        
        $json_content = @file_get_contents($urlLoad, false, $context);
        
        // Só salva se vier um JSON válido e não for erro
        if ($json_content) {
            $testDecode = json_decode($json_content, true);
            if(is_array($testDecode) && !isset($testDecode['error'])) {
                file_put_contents($cacheFile, $json_content);
            }
        }
    }

    if (!file_exists($cacheFile)) return [];

    $json_data = file_get_contents($cacheFile);
    $rows = json_decode($json_data, true); 

    if (!is_array($rows) || isset($rows['error']) || empty($rows)) return [];

    // Mapeamento de Colunas
    // Linha 0 é o cabeçalho
    $header = array_map('mb_strtolower', array_map('trim', $rows[0]));
    
    $findCol = function($keywords) use ($header) {
        foreach ($header as $idx => $colName) {
            foreach ($keywords as $key) {
                if (strpos($colName, $key) !== false) return $idx;
            }
        }
        return -1;
    };

    $idxEmpresa   = $findCol(['clientes']);
    $idxRota      = $findCol(['rota', 'linha']);
    $idxMotorista = $findCol(['motorista', 'condutor']);
    $idxReserva   = $findCol(['reserva']); // Coluna Reserva
    $idxEscala    = $findCol(['escala']); 
    $idxEnviada   = $findCol(['enviada']); 
    $idxProg      = $findCol(['ini']); 
    $idxReal      = $findCol(['real', 'realizado']);
    $idxObs       = $findCol(['observação', 'obs']);
    $idxManut     = $findCol(['manutenção', 'manut']);
    $idxCarro     = $findCol(['aguardando', 'carro']);
    $idxRA        = $findCol(['ra', 'r.a', 'registro']); 

    $dadosProcessados = [];
    
    // Função para garantir formato HH:mm
    $limparHorario = function($val) {
        if(empty($val)) return '';
        return substr(trim($val), 0, 5); 
    };

    // Itera dados (começa do 1 para pular cabeçalho)
    for ($i = 1; $i < count($rows); $i++) {
        $r = $rows[$i];
        if (empty($r[$idxEmpresa] ?? '') && empty($r[$idxRota] ?? '')) continue;

        $valManut = $r[$idxManut] ?? '';
        $valCarro = $r[$idxCarro] ?? '';

        $dadosProcessados[] = [
            'empresa'      => $r[$idxEmpresa] ?? '---',
            'rota'         => $r[$idxRota] ?? '---',
            'motorista'    => $r[$idxMotorista] ?? 'Não Definido',
            'reserva'      => $r[$idxReserva] ?? '',
            'frota_escala' => $r[$idxEscala] ?? '---',
            'frota_enviada'=> $r[$idxEnviada] ?? '---',
            'h_prog'       => $limparHorario($r[$idxProg] ?? ''), 
            'h_real'       => $limparHorario($r[$idxReal] ?? ''),
            'obs'          => $r[$idxObs] ?? '',       
            'ra_val'       => $r[$idxRA] ?? '',        
            'manutencao'   => (stripos($valManut, 'sim') !== false || stripos($valManut, 'manuten') !== false),
            'aguardando'   => (stripos($valCarro, 'sim') !== false || stripos($valCarro, 'aguard') !== false),
        ];
    }
    return $dadosProcessados;
}

// 4. EXECUÇÃO
// Passamos o nome do arquivo dinâmico aqui
$lista_dados = getDadosJson(GOOGLE_SCRIPT_URL, $data_filtro, $nome_arquivo_cache, CACHE_TIME);

// Ordenação por horário
usort($lista_dados, function($a, $b) {
    if ($a['h_prog'] == $b['h_prog']) return 0;
    return ($a['h_prog'] < $b['h_prog']) ? -1 : 1;
});

// 5. CÁLCULO DE KPIS
// -------------------------------------------------------------------------
$kpi_total = count($lista_dados);
$kpi_confirmados = 0; $kpi_pendentes = 0; $kpi_manutencao = 0; $kpi_aguardando = 0; $kpi_cobrir = 0;
$empresas_unicas = [];

foreach ($lista_dados as $linha) {
    if (!empty($linha['ra_val']) && trim($linha['ra_val']) !== '') $kpi_confirmados++; else $kpi_pendentes++;
    if ($linha['manutencao']) $kpi_manutencao++;
    if ($linha['aguardando']) $kpi_aguardando++;
    if (stripos($linha['obs'], 'cobrir') !== false) $kpi_cobrir++;
    if ($linha['empresa'] !== '---') $empresas_unicas[$linha['empresa']] = $linha['empresa'];
}
asort($empresas_unicas);
$filtro_empresa_sel = $_GET['empresa'] ?? '';
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>MIMO - Painel de Frota</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        :root { --sidebar-bg: #0f172a; --sidebar-width: 260px; --sidebar-collapsed-width: 80px; --card-blue: #1e3a8a; --card-green: #10b981; --card-amber: #f59e0b; --alert-red: #dc2626; --alert-orange: #f97316; --alert-purple: #7e22ce; --bg-body: #f3f4f6; }
        body { background-color: #f8f9fa; font-family: 'Segoe UI', sans-serif; overflow-x: hidden; }
         .sidebar { 
        background-color: #0b1f3a; 
        color: white; 
        min-height: 100vh; 
        width: 250px; 
        position: fixed; 
        z-index: 1000; 
        transition: all 0.3s ease; /* Transição suave */
        overflow-y: auto;
    }
    .sidebar-header { padding: 1.5rem; font-size: 1.5rem; font-weight: 800; letter-spacing: 2px; text-align: center; border-bottom: 1px solid rgba(255,255,255,0.1); margin-bottom: 10px; }
        .sidebar a { 
        color: #d1d5db; 
        display: flex; /* Flex para alinhar ícone e texto */
        align-items: center;
        padding: 14px 20px; 
        text-decoration: none; 
        border-left: 4px solid transparent; 
        font-weight: 500; 
        white-space: nowrap; /* Impede quebra de texto */
        overflow: hidden;
    }
        .content.toggled { margin-left: var(--sidebar-collapsed-width); }
        .top-header { background: white; padding: 1rem 2rem; border-bottom: 1px solid #e5e7eb; display: flex; justify-content: space-between; align-items: center; position: sticky; top: 0; z-index: 900; }
        .kpi-card { border-radius: 12px; padding: 1.5rem; color: white; text-align: center; height: 100%; display: flex; flex-direction: column; justify-content: center; box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1); }
        .kpi-title { font-size: 0.75rem; text-transform: uppercase; font-weight: 700; opacity: 0.9; margin-bottom: 0.5rem; }
        .kpi-value { font-size: 2.5rem; font-weight: 800; line-height: 1; }
        .bg-mimo-blue { background-color: var(--card-blue); } .bg-mimo-green { background-color: var(--card-green); } .bg-mimo-amber { background-color: var(--card-amber); }
        .kpi-alert-card { background: white; border-radius: 10px; padding: 1rem; display: flex; align-items: center; justify-content: space-between; border-left: 5px solid #ccc; box-shadow: 0 2px 4px rgba(0,0,0,0.05); transition: transform 0.2s; }
        .kpi-alert-card:hover { transform: translateY(-2px); }
        .alert-label { font-size: 0.8rem; font-weight: 700; text-transform: uppercase; color: #64748b; }
        .alert-number { font-size: 1.8rem; font-weight: 800; color: #334155; }
        .alert-icon { font-size: 1.5rem; opacity: 0.2; }
        .border-red { border-left-color: var(--alert-red); } .text-red { color: var(--alert-red); }
        .border-orange { border-left-color: var(--alert-orange); } .text-orange { color: var(--alert-orange); }
        .border-purple { border-left-color: var(--alert-purple); } .text-purple { color: var(--alert-purple); }
        .card-table { background: white; border-radius: 12px; border: 1px solid #e5e7eb; box-shadow: 0 1px 3px rgba(0,0,0,0.05); overflow: hidden; }
        .table-custom thead th { background-color: #f8fafc; color: #64748b; font-size: 0.75rem; text-transform: uppercase; font-weight: 700; padding: 1rem; border-bottom: 1px solid #e2e8f0; }
        .table-custom tbody td { padding: 1rem; vertical-align: middle; color: #334155; font-size: 0.875rem; border-bottom: 1px solid #f1f5f9; }
        .badge-status-pendente { background-color: #fef3c7; color: #b45309; border: 1px solid #fcd34d; }
        .badge-status-ok { background-color: #d1fae5; color: #065f46; border: 1px solid #6ee7b7; }
        .badge-status-erro { background-color: #fee2e2; color: #991b1b; border: 1px solid #fca5a5; }
        .divergent-text { color: #dc2626; font-weight: 700; text-decoration: underline; }
        .search-input { border-radius: 20px; background: #f8fafc; border: 1px solid #cbd5e1; padding: 5px 15px 5px 35px; width: 250px; }
        .search-wrapper { position: relative; }
        .search-wrapper i { position: absolute; left: 12px; top: 50%; transform: translateY(-50%); color: #94a3b8; }
        .btn-edit-obs { cursor: pointer; color: #64748b; transition: color 0.2s; }
        .btn-edit-obs:hover { color: #2563eb; }
        /* --- CONTEÚDO --- */
    .content { 
        margin-left: 250px; 
        padding: 30px; 
        transition: all 0.3s ease; 
    }
    .content.toggled { margin-left: 80px; } /* Ajusta margem quando fechado */
        /* Imagem do Logo */
    .sidebar .logo-container img { transition: all 0.3s; max-width: 160px; }

    /* --- ESTADO RETRAÍDO (TOGGLED) --- */
    .sidebar.toggled { width: 80px; }
    .sidebar.toggled .logo-container img { max-width: 50px; } /* Diminui logo */
    .sidebar.toggled a span { display: none; } /* Esconde o texto */
    .sidebar.toggled a { justify-content: center; padding: 14px 0; } /* Centraliza ícones */
    .sidebar.toggled a i { margin-right: 0 !important; }
    .sidebar a i { min-width: 30px; font-size: 1.1rem; } /* Largura fixa para o ícone */
     .sidebar a.active, .sidebar a:hover { background-color: #1b2e52; color: white; border-left-color: #0d6efd; }
    </style>
</head>
<body>

<div class="sidebar d-flex flex-column" id="sidebar">
    <div class="text-center py-4 bg-dark bg-opacity-25 logo-container">
        <img src="https://viacaomimo.com.br/wp-content/uploads/2023/07/Background-12-1.png" alt="Logo">
    </div>
    <a href="#" title="Dashboard"><i class="bi bi-speedometer2 me-2"></i><span>Dashboard</span></a>
    <a href="#" title="Rotas"><i class="bi bi-map me-2"></i><span>Rotas</span></a>
    <a href="#" title="Veículos"><i class="bi bi-bus-front me-2"></i><span>Veículos</span></a>
    <a href="#" title="Motoristas"><i class="bi bi-person-vcard me-2"></i><span>Motoristas</span></a>
    <a href="#" class="active" title="Escala"><i class="bi bi-speedometer2 me-2"></i><span>Escala</span></a>
    <a href="relatorio.php" title="Relatórios"><i class="bi bi-file-earmark-text me-2"></i><span>Relatórios</span></a>
    <?php if (($_SESSION['user_role'] ?? '') === 'admin'): ?>
        <a href="admin.php" title="Usuários"><i class="bi bi-people-fill me-2"></i><span>Usuários</span></a>
    <?php endif; ?>
    <a href="logout.php" class="mt-auto text-danger border-top border-secondary" title="Sair"><i class="bi bi-box-arrow-right me-2"></i><span>Sair</span></a>
</div>

<div class="content" id="content">
    <header class="top-header">
        <div class="d-flex align-items-center gap-3">
            <button class="btn btn-light btn-sm shadow-sm" id="btnToggle"><i class="bi bi-list fs-5"></i></button>
            <div>
                <h5 class="mb-0 fw-bold text-dark">Visão Geral das Linhas</h5>
                <small class="text-muted">Filtro de Data: <strong><?php echo $data_filtro; ?></strong></small>
            </div>
        </div>
        <div class="d-flex align-items-center gap-3">
            <form method="GET" class="d-flex gap-2">
                <input type="text" name="data" class="form-control form-control-sm" value="<?php echo $data_filtro; ?>" style="width: 120px;" placeholder="dd/mm/aaaa">
                <button class="btn btn-dark btn-sm">Ir</button>
            </form>
            <div class="search-wrapper">
                <i class="bi bi-search"></i>
                <input type="text" id="searchInput" class="search-input" placeholder="Buscar na tela...">
            </div>
        </div>
    </header>

    <div class="p-4">
        <div class="row g-4 mb-3">
            <div class="col-md-4"><div class="kpi-card bg-mimo-blue"><div class="kpi-title">Total de Linhas</div><div class="kpi-value" id="count-total"><?php echo $kpi_total; ?></div></div></div>
            <div class="col-md-4"><div class="kpi-card bg-mimo-green"><div class="kpi-title">Confirmadas (RA)</div><div class="kpi-value" id="count-confirmadas"><?php echo $kpi_confirmados; ?></div></div></div>
            <div class="col-md-4"><div class="kpi-card bg-mimo-amber"><div class="kpi-title">Pendentes</div><div class="kpi-value" id="count-pendentes"><?php echo $kpi_pendentes; ?></div></div></div>
        </div>
        <div class="row g-3 mb-4">
            <div class="col-md-4"><div class="kpi-alert-card border-red"><div><div class="alert-label text-red">Em Manutenção</div><div class="alert-number text-red" id="count-manutencao"><?php echo $kpi_manutencao; ?></div></div><div class="alert-icon text-red"><i class="bi bi-wrench"></i></div></div></div>
            <div class="col-md-4"><div class="kpi-alert-card border-orange"><div><div class="alert-label text-orange">Aguardando Carro</div><div class="alert-number text-orange" id="count-aguardando"><?php echo $kpi_aguardando; ?></div></div><div class="alert-icon text-orange"><i class="bi bi-cone-striped"></i></div></div></div>
            <div class="col-md-4"><div class="kpi-alert-card border-purple"><div><div class="alert-label text-purple">Cobrir</div><div class="alert-number text-purple" id="count-cobrir"><?php echo $kpi_cobrir; ?></div></div><div class="alert-icon text-purple"><i class="bi bi-exclamation-triangle-fill"></i></div></div></div>
        </div>

        <div class="bg-white p-3 rounded shadow-sm border mb-4">
            <form method="GET" class="row g-3 align-items-end">
                <input type="hidden" name="data" value="<?php echo $data_filtro; ?>">
                <div class="col-md-3">
                    <label class="form-label small fw-bold text-secondary mb-1">Empresa</label>
                    <select name="empresa" class="form-select form-select-sm" onchange="this.form.submit()">
                        <option value="">Todas</option>
                        <?php foreach ($empresas_unicas as $emp): ?>
                            <option value="<?php echo $emp; ?>" <?php echo ($filtro_empresa_sel == $emp) ? 'selected' : ''; ?>><?php echo $emp; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label small fw-bold text-secondary mb-1">Status (Visual)</label>
                    <select id="filtroStatusJS" class="form-select form-select-sm" onchange="saveAndApply()">
                        <option value="">Todos</option>
                        <option value="pendente">Aguardando</option>
                        <option value="confirmado">Confirmado</option>
                        <option value="manutencao">Manutenção</option>
                    </select>
                </div>
            </form>
        </div>

        <div class="card-table">
            <div class="p-3 border-bottom bg-white d-flex justify-content-between align-items-center">
                <h6 class="mb-0 fw-bold text-dark">Operação</h6>
                <span class="badge bg-light text-dark border">Atualiza em <span id="timer-display">60</span>s</span>
            </div>
            <div class="table-responsive">
                <table class="table table-custom mb-0 w-100">
                    <thead>
                        <tr>
                            <th>Empresa / Rota</th>
                            <th class="text-center">Frotas</th>
                            <th>Motorista</th>
                            <th>Detalhes & Obs</th>
                            <th class="text-center">Status</th>
                            <th class="text-end">Horário</th>
                        </tr>
                    </thead>
                    <tbody id="tabela-veiculos">
                        <?php if (empty($lista_dados)): ?>
                             <tr><td colspan="6" class="text-center py-5 text-muted">Não foram encontrados dados para <?php echo $data_filtro; ?>.</td></tr>
                        <?php else:
                            foreach ($lista_dados as $row):
                                if ($filtro_empresa_sel && $row['empresa'] !== $filtro_empresa_sel) continue;

                                $divergencia = ($row['frota_escala'] != $row['frota_enviada']);
                                $realizou = (!empty($row['ra_val']) && trim($row['ra_val']) !== '');
                                $isManut = $row['manutencao'] ? 1 : 0;
                                $isAguard = $row['aguardando'] ? 1 : 0;
                                $isCobrir = (stripos($row['obs'], 'cobrir') !== false) ? 1 : 0;

                                $statusJS = 'pendente';
                                $statusHTML = '<span class="badge rounded-pill badge-status-pendente px-3">Aguardando</span>';

                                if ($row['manutencao']) {
                                    $statusJS = 'manutencao';
                                    $statusHTML = '<span class="badge rounded-pill badge-status-erro px-3">Manutenção</span>';
                                } elseif ($realizou) {
                                    $statusJS = 'confirmado';
                                    $statusHTML = '<span class="badge rounded-pill badge-status-ok px-3">Confirmado</span>';
                                }
                        ?>
                        <tr data-status-js="<?php echo $statusJS; ?>" data-is-manut="<?php echo $isManut; ?>" data-is-aguard="<?php echo $isAguard; ?>" data-is-cobrir="<?php echo $isCobrir; ?>">
                            <td>
                                <div class="fw-bold text-dark"><?php echo $row['empresa']; ?></div>
                                <div class="small text-muted text-truncate" style="max-width: 250px;"><?php echo $row['rota']; ?></div>
                            </td>
                            <td class="text-center">
                                <div class="small text-muted"><?php echo $row['frota_escala']; ?> / <strong class="<?php echo $divergencia ? 'divergent-text' : ''; ?>"><?php echo $row['frota_enviada']; ?></strong></div>
                            </td>
                            <td>
                                <div class="d-flex flex-column gap-1">
                                    <div class="d-flex align-items-center gap-2">
                                        <div class="bg-light rounded-circle p-2 text-secondary"><i class="bi bi-person-fill"></i></div>
                                        <span class="fw-medium small text-dark"><?php echo $row['motorista']; ?></span>
                                    </div>
                                    <?php if(!empty($row['reserva'])): ?>
                                        <div class="ms-4 small text-muted d-flex align-items-center gap-1">
                                            <i class="bi bi-arrow-return-right"></i> Res: <strong><?php echo $row['reserva']; ?></strong>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td>
                                <div class="d-flex justify-content-between align-items-start">
                                    <div class="d-flex flex-column gap-1">
                                        <?php if($row['manutencao']): ?><span class="badge bg-danger bg-opacity-10 text-danger border border-danger py-1" style="font-size:0.7rem">Em Manutenção</span><?php endif; ?>
                                        <?php if($row['aguardando']): ?><span class="badge bg-warning bg-opacity-10 text-warning border border-warning py-1" style="font-size:0.7rem">Aguard. Carro</span><?php endif; ?>
                                        
                                        <?php if(!empty($row['obs'])): ?>
                                            <small class="text-secondary fst-italic" title="<?php echo $row['obs']; ?>">
                                                <?php echo ($isCobrir ? '<strong class="text-purple">COBRIR</strong> ' : '') . mb_strimwidth($row['obs'], 0, 30, "..."); ?>
                                            </small>
                                        <?php endif; ?>

                                        <?php if($realizou): ?>
                                            <small class="text-success fw-bold" style="font-size: 0.75rem;">RA: <?php echo $row['ra_val']; ?></small>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </td>
                            <td class="text-center"><?php echo $statusHTML; ?></td>
                            <td class="text-end">
                                <div><small class="text-muted">Prog:</small> <strong><?php echo $row['h_prog']; ?></strong></div>
                                <?php if(!empty($row['h_real']) && $row['h_real'] !== 'N/D'): ?><div><small class="text-muted">Real:</small> <?php echo $row['h_real']; ?></div><?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
    const btnToggle = document.getElementById('btnToggle');
    const sidebar = document.getElementById('sidebar');
    const content = document.getElementById('content');
    btnToggle.addEventListener('click', () => { sidebar.classList.toggle('toggled'); content.classList.toggle('toggled'); });

    // REFRESH
    const timerDisplay = document.getElementById('timer-display');
    let timeLeft = 60;
    setInterval(() => {
        timeLeft--;
        if(timerDisplay) timerDisplay.innerText = timeLeft;
        if (timeLeft <= 0) window.location.reload();
    }, 1000);

    // FILTROS
    const searchInput = document.getElementById('searchInput');
    const filtroStatus = document.getElementById('filtroStatusJS');

    function aplicarFiltrosJS() {
        const termo = searchInput.value.toLowerCase();
        const statusVal = filtroStatus.value;
        const rows = document.querySelectorAll('#tabela-veiculos tr');
        let cTotal=0, cConf=0, cPend=0, cManut=0, cAguard=0, cCobrir=0;

        rows.forEach(row => {
            const txt = row.innerText.toLowerCase();
            const st = row.getAttribute('data-status-js');
            const isManut = row.getAttribute('data-is-manut') === '1';
            const isAguard = row.getAttribute('data-is-aguard') === '1';
            const isCobrir = row.getAttribute('data-is-cobrir') === '1';
            
            if(txt.includes(termo) && (statusVal === '' || st === statusVal)) {
                row.style.display = '';
                cTotal++;
                if(st === 'confirmado') cConf++; else cPend++;
                if(isManut) cManut++;
                if(isAguard) cAguard++;
                if(isCobrir) cCobrir++;
            } else { row.style.display = 'none'; }
        });

        if(document.getElementById('count-total')) document.getElementById('count-total').innerText = cTotal;
        if(document.getElementById('count-confirmadas')) document.getElementById('count-confirmadas').innerText = cConf;
        if(document.getElementById('count-pendentes')) document.getElementById('count-pendentes').innerText = cPend;
        if(document.getElementById('count-manutencao')) document.getElementById('count-manutencao').innerText = cManut;
        if(document.getElementById('count-aguardando')) document.getElementById('count-aguardando').innerText = cAguard;
        if(document.getElementById('count-cobrir')) document.getElementById('count-cobrir').innerText = cCobrir;
    }

    function saveAndApply() {
        localStorage.setItem('mimo_frota_search', searchInput.value);
        localStorage.setItem('mimo_frota_status', filtroStatus.value);
        aplicarFiltrosJS();
    }
    searchInput.addEventListener('keyup', saveAndApply);
    
    document.addEventListener('DOMContentLoaded', () => {
        const savedSearch = localStorage.getItem('mimo_frota_search');
        const savedStatus = localStorage.getItem('mimo_frota_status');
        if (savedSearch) searchInput.value = savedSearch;
        if (savedStatus) filtroStatus.value = savedStatus;
        aplicarFiltrosJS();
    });

    // EDIÇÃO
    function editarObs(empresa, rota, obsAtual, dataFiltro) {
        const scriptUrl = "<?php echo (defined('GOOGLE_SCRIPT_URL') && GOOGLE_SCRIPT_URL != 'COLE_A_URL_DO_GOOGLE_SCRIPT_AQUI') ? GOOGLE_SCRIPT_URL : ''; ?>";
        if (!scriptUrl) { alert("Configure a URL do Script!"); return; }

        const novaObs = prompt("Editar OBS (" + rota + " - " + dataFiltro + "):", obsAtual);
        if (novaObs !== null && novaObs !== obsAtual) {
            document.body.style.cursor = 'wait';
            const rows = document.querySelectorAll('#tabela-veiculos tr');
            rows.forEach(row => {
                if(row.innerText.includes(empresa) && row.innerText.includes(rota)) {
                    const smallObs = row.querySelector('small.fst-italic');
                    if(smallObs) { smallObs.innerText = novaObs + " (Salvando...)"; smallObs.style.color = "orange"; }
                }
            });

            const urlCompleta = scriptUrl + 
                "?action=edit" +
                "&empresa=" + encodeURIComponent(empresa) + 
                "&rota=" + encodeURIComponent(rota) + 
                "&obs=" + encodeURIComponent(novaObs) +
                "&data=" + encodeURIComponent(dataFiltro);

            fetch(urlCompleta)
            .then(response => response.text())
            .then(data => {
                if(data.includes("Sucesso")) {
                     window.location.href = window.location.pathname + "?data=" + encodeURIComponent(dataFiltro) + "&force_refresh=1";
                } else {
                    alert("Erro do Google: " + data);
                    window.location.reload(); 
                }
            })
            .catch(error => {
                alert("Erro de conexão.");
                console.error(error);
                window.location.reload();
            })
            .finally(() => {
                document.body.style.cursor = 'default';
            });
        }
    }
</script>
</body>
</html>
