<?php
// --- CONFIGURAÇÃO E SESSÃO ---
if (session_status() === PHP_SESSION_NONE) { session_start(); }

require_once 'config.php'; 
require_once 'menus.php'; 
require_once 'includes/page_logic.php';

// Segurança
if (!isset($_SESSION['user_logged_in']) || $_SESSION['user_logged_in'] !== true) {
    header("Location: login.php"); exit;
}
verificarPermissaoPagina('dashboard');

// Configurações Iniciais
$empresas_permitidas = $_SESSION['allowed_companies'] ?? [];
date_default_timezone_set('America/Sao_Paulo'); 
$hora_atual = date('H:i');

// Carga de dados (Server Side)
$dados_dashboard = handle_index_data($empresas_permitidas);
extract($dados_dashboard); // Disponibiliza $todas_linhas, $qtd_total, etc.

// Filtros PHP
$filtro_empresa = $_GET['empresa'] ?? '';
$filtro_sentido = $_GET['sentido'] ?? '';
$primeiro_veiculo_json = json_encode($todas_linhas[0] ?? null, JSON_UNESCAPED_UNICODE);

// Lista única de empresas
$lista_empresas_unicas = [];
if (!empty($todas_linhas)) {
    foreach ($todas_linhas as $l) {
        if (!empty($l['empresa']['nome'])) $lista_empresas_unicas[$l['empresa']['nome']] = $l['empresa']['nome'];
    }
    asort($lista_empresas_unicas);
}

// --- FUNÇÃO DE RENDERIZAÇÃO (REUTILIZÁVEL) ---
// Usada tanto no carregamento inicial quanto no AJAX para economizar processamento
function renderizar_linhas_tabela($linhas, $filtro_empresa, $filtro_sentido, $hora_atual) {
    if (empty($linhas)) {
        echo '<tr><td colspan="12" class="text-center py-4 text-muted">Nenhum veículo encontrado com os filtros selecionados.</td></tr>';
        return;
    }

    $contador = 0;
    foreach ($linhas as $linha) {
        // Filtros PHP
        if (!empty($filtro_empresa) && ($linha['empresa']['nome'] ?? '') !== $filtro_empresa) continue;
        
        $sIdaRaw = $linha['sentidoIDA'] ?? $linha['sentidoIda'] ?? true;
        $sentido_ida_bool = filter_var($sIdaRaw, FILTER_VALIDATE_BOOLEAN);
        $sentido_string = $sentido_ida_bool ? 'ida' : 'volta';

        if (!empty($filtro_sentido) && $sentido_string !== $filtro_sentido) continue;
        
        $contador++;
        $id_linha = $linha['idLinha'] ?? '';
        $prog = $linha['horarioProgramado'] ?? '23:59';
        $real = $linha['horarioReal'] ?? 'N/D';
        $data_prog_fim = htmlspecialchars($linha['horariofinalProgramado'] ?? 'N/D', ENT_QUOTES);
        
        // Cache
        $timestamp_cache = $linha['previsao_fim_ts'] ?? ''; 
        $tem_cache = !empty($timestamp_cache);
        $valor_cache = $tem_cache ? date('H:i', $timestamp_cache) : '--:--';

        // Ícones e Atributos
        $sentido_ida_attr = $sentido_ida_bool ? 'true' : 'false';
        $icon_sentido = $sentido_ida_bool 
            ? '<i class="bi bi-arrow-right-circle-fill text-primary ms-1" title="IDA"></i>' 
            : '<i class="bi bi-arrow-left-circle-fill text-warning ms-1" title="VOLTA"></i>';

        // Lógica de Status (Otimizada)
        $status_html = '';
        $atraso_saida = false;
        $atraso_percurso = false;
        $tolerancia = 10;
        
        // Helper para diff
        $diffMinutos = function($h1, $h2) {
            if ($h1 == 'N/D' || $h2 == 'N/D' || empty($h1) || empty($h2)) return 0;
            $t1 = DateTime::createFromFormat('H:i', $h1); $t2 = DateTime::createFromFormat('H:i', $h2);
            return ($t1 && $t2) ? ($t2->getTimestamp() - $t1->getTimestamp()) / 60 : 0;
        };

        if (($linha['categoria'] ?? '') == 'Carro desligado') {
             $status_html = '<span class="badge bg-secondary rounded-pill">Desligado</span>';
        } elseif ($real == 'N/D' || empty($real)) {
             $diff = $diffMinutos($prog, $hora_atual);
             if ($diff > $tolerancia) {
                 $atraso_saida = true;
                 $status_html = '<span class="badge rounded-pill bg-danger blink-animation">Atrasado (Inicial)</span>';
             } else {
                 $status_html = '<span class="badge bg-light text-dark border">Aguardando</span>';
             }
        } else {
             $diff_saida = $diffMinutos($prog, $real);
             
             if ($sentido_ida_bool && $tem_cache && $data_prog_fim != 'N/D') {
                 if ($diffMinutos($data_prog_fim, $valor_cache) > $tolerancia) $atraso_percurso = true;
             }

             if ($atraso_percurso) {
                 $status_html = ($diff_saida > $tolerancia) 
                    ? '<span class="badge bg-danger rounded-pill">Atrasado (P. Inicial)</span>' 
                    : '<span class="badge bg-danger rounded-pill">Atrasado (Percurso)</span>';
             } elseif ($diff_saida > $tolerancia) {
                 $status_html = '<span class="badge bg-danger rounded-pill">Atrasado (P. Inicial)</span>';
             } else {
                 $status_html = '<span class="badge bg-success rounded-pill">Pontual</span>';
             }
        }

        $tr_attr = ($atraso_saida ? 'data-atraso-tipo="saida"' : '') . ' data-sentido-ida="' . $sentido_ida_attr . '" data-id-linha="' . htmlspecialchars($id_linha) . '"';
        $placa_clean = htmlspecialchars($linha['veiculo']['veiculo'] ?? '', ENT_QUOTES);
        $ja_saiu = ($real != 'N/D');
        $deve_calcular = ($ja_saiu && ($linha['categoria'] ?? '') != 'Carro desligado' && !$tem_cache) ? 'true' : 'false';
        
        $classe_prev = "text-muted";
        if ($tem_cache && $data_prog_fim != 'N/D') {
             $classe_prev = ($valor_cache > $data_prog_fim) ? "text-danger fw-bold" : "text-success fw-bold";
        }
        
        // Output da Linha
        echo "<tr $tr_attr>
            <td>".htmlspecialchars($linha['empresa']['nome'] ?? 'N/D')."</td>
            <td>".htmlspecialchars($linha['descricaoLinha'] ?? 'N/D')." $icon_sentido</td>
            <td class='fw-bold text-primary'>$placa_clean</td>
            <td id='prev-ini-$placa_clean' class='text-muted small'>--:--</td>
            <td class='".($atraso_saida ? 'text-danger fw-bold' : '')."'>".htmlspecialchars($prog)."</td>
            <td>".htmlspecialchars($real)."</td>
            <td><strong>".htmlspecialchars($linha['horariofinalProgramado'] ?? 'N/D')."</strong></td>
            <td id='prev-fim-$placa_clean' class='$classe_prev celula-previsao' 
                data-placa='$placa_clean' 
                data-prog-fim='$data_prog_fim' 
                data-ts-cache='$timestamp_cache' 
                data-calcular='$deve_calcular' 
                data-id-linha='".htmlspecialchars($id_linha)."'>$valor_cache</td>
            <td title='".htmlspecialchars($linha['ultimaData'] ?? '')."'>".htmlspecialchars($linha['ultimaData'] ?? 'N/D')."</td>
            <td>$status_html</td>
            <td class='text-center'>
                <button class='btn btn-outline-primary btn-xs rounded-circle' onclick=\"buscarRastreamentoinicial('$placa_clean', '".htmlspecialchars($linha['localinicial'] ?? 'N/D', ENT_QUOTES)."', '".htmlspecialchars($prog, ENT_QUOTES)."', '".htmlspecialchars($id_linha)."', this)\"><i class='bi bi-clock'></i></button>
            </td>
            <td class='text-center'>
                <button class='btn btn-primary btn-sm rounded-circle shadow-sm' onclick=\"buscarRastreamento('$placa_clean', '".htmlspecialchars($linha['localfinal'] ?? 'N/D', ENT_QUOTES)."', '".htmlspecialchars($linha['horariofinalProgramado'] ?? 'N/D', ENT_QUOTES)."', '".htmlspecialchars($id_linha)."', this)\"><i class='bi bi-geo-alt-fill'></i></button>
            </td>
        </tr>";
    }
    
    if ($contador === 0) {
        echo '<tr><td colspan="12" class="text-center py-4 text-muted">Nenhum veículo encontrado com os filtros selecionados.</td></tr>';
    }
}

// --- AJAX HANDLER (OTIMIZAÇÃO) ---
// Se a requisição for AJAX pedindo apenas o corpo da tabela, retorna e encerra.
if (isset($_GET['ajax_tbody'])) {
    renderizar_linhas_tabela($todas_linhas, $filtro_empresa, $filtro_sentido, $hora_atual);
    exit; // Encerra o script aqui para não renderizar o HTML completo novamente
}
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Viação Mimo - Dashboard</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <link rel="stylesheet" href="https://unpkg.com/leaflet-routing-machine@3.2.12/dist/leaflet-routing-machine.css" />
    <style>
    body{background-color:#f8f9fa;font-family:'Segoe UI',sans-serif;overflow-x:hidden}.sidebar{background-color:#0b1f3a;color:#fff;min-height:100vh;width:250px;position:fixed;z-index:1000;transition:all .3s ease;overflow-y:auto}.sidebar a{color:#d1d5db;display:flex;align-items:center;padding:14px 20px;text-decoration:none;border-left:4px solid transparent;font-weight:500;white-space:nowrap;overflow:hidden}.sidebar a i{min-width:30px;font-size:1.1rem}.sidebar a.active,.sidebar a:hover{background-color:#1b2e52;color:#fff;border-left-color:#0d6efd}.sidebar .logo-container img{transition:all .3s;max-width:160px}.sidebar.toggled{width:80px}.sidebar.toggled .logo-container img{max-width:50px}.sidebar.toggled a span{display:none}.sidebar.toggled a{justify-content:center;padding:14px 0}.sidebar.toggled a i{margin-right:0!important}.content{margin-left:250px;padding:30px;transition:all .3s ease}.content.toggled{margin-left:80px}.card-summary{border-radius:12px;padding:20px;color:#fff;text-align:center;position:relative;overflow:hidden;box-shadow:0 4px 6px rgba(0,0,0,.1)}.card-summary h5{font-size:.9rem;opacity:.9;margin-bottom:5px;text-transform:uppercase}.card-summary h3{font-size:2rem;font-weight:700;margin:0}.card-blue{background:linear-gradient(135deg,#0b1f3a 0,#1e3a8a 100%)}.card-red{background:linear-gradient(135deg,#b91c1c 0,#dc2626 100%)}.card-green{background:linear-gradient(135deg,#047857 0,#10b981 100%)}.bg-secondary{background:linear-gradient(135deg,#4b5563 0,#6b7280 100%)!important}.bg-info{background:linear-gradient(135deg,#0891b2 0,#06b6d4 100%)!important}.bg-warning{background:linear-gradient(135deg,#f59e0b 0,#d97706 100%)!important}.table-responsive{background:#fff;border-radius:12px;box-shadow:0 2px 10px rgba(0,0,0,.05);padding:5px}.table thead{background-color:#f1f5f9}.table thead th{color:#475569;font-weight:600;border-bottom:2px solid #e2e8f0;padding:15px;cursor:pointer;white-space:nowrap}.table tbody td{padding:15px;vertical-align:middle;color:#334155;white-space:normal}th:hover{background-color:#e2e8f0;color:#0f172a}.search-bar{border-radius:20px;border:1px solid #cbd5e1;padding-left:40px;height:45px}.search-icon{position:absolute;left:15px;top:12px;color:#94a3b8}.modal-content{border:none;border-radius:16px;overflow:hidden}#mapaRota{height:550px;width:100%;background-color:#e9ecef;z-index:1}.leaflet-routing-container{display:none!important}.mini-loader{width:1rem;height:1rem;border-width:.15em}.table-ultra-compact{font-size:.9rem}.table-ultra-compact td,.table-ultra-compact th{padding-top:2px!important;padding-bottom:2px!important;padding-left:4px!important;padding-right:4px!important;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:150px}.col-narrow{width:1%;white-space:nowrap}.bi{font-size:.9em}.btn-xs{padding:.1rem .3rem;font-size:.7rem;line-height:1}.blink-animation{animation:blinker 1.5s linear infinite}@keyframes blinker{50%{opacity:.5}}.filter-bar{background-color:#fff;border-radius:12px;padding:15px;margin-bottom:20px;box-shadow:0 2px 5px rgba(0,0,0,.05)}
    </style>
</head>
<body>

<div class="sidebar d-flex flex-column" id="sidebar">
    <div class="text-center py-4 bg-dark bg-opacity-25 logo-container">
        <img src="https://viacaomimo.com.br/wp-content/uploads/2023/07/Background-12-1.png" alt="Logo">
    </div>
    <?php 
    foreach ($menu_itens as $chave => $item): 
        $is_admin = ($_SESSION['user_role'] ?? '') === 'admin';
        $tem_permissao = in_array($chave, $permissoes_usuario);
        if ($is_admin || $tem_permissao):
            $classe_active = ($pagina_atual == $item['link'] || ($chave == 'escala' && $pagina_atual == 'escala.php')) ? 'active' : '';
    ?>
        <a href="<?php echo $item['link']; ?>" class="<?php echo $classe_active; ?>" title="<?php echo $item['label']; ?>">
            <i class="bi <?php echo $item['icon']; ?> me-2"></i><span><?php echo $item['label']; ?></span>
        </a>
    <?php endif; endforeach; ?>
    <?php if (($_SESSION['user_role'] ?? '') === 'admin'): ?>
        <a href="admin.php" title="Usuários" class="<?php echo ($pagina_atual == 'admin.php') ? 'active' : ''; ?>">
            <i class="bi bi-people-fill me-2"></i><span>Usuários</span>
        </a>
    <?php endif; ?>
    <a href="logout.php" class="mt-auto text-danger border-top border-secondary" title="Sair">
        <i class="bi bi-box-arrow-right me-2"></i><span>Sair</span>
    </a>
</div>

<div class="content" id="content">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div class="d-flex align-items-center gap-3">
            <button class="btn btn-outline-dark border-0 shadow-sm" id="btnToggleMenu"><i class="bi bi-list fs-5"></i></button>
            <div><h4 class="fw-bold text-dark mb-1">Visão Geral da Frota</h4><p class="text-muted small mb-0">Monitoramento em tempo real</p></div>
        </div>
        <div class="d-flex gap-2 w-50 justify-content-end">
            <?php if (($_SESSION['user_role'] ?? '') === 'admin'): ?>
            <button class="btn btn-sm btn-dark" onclick="mostrarDebug()"><i class="bi bi-bug"></i> Debug</button>
            <?php endif; ?>
            <div class="position-relative w-50">
                <i class="bi bi-search search-icon"></i>
                <input type="text" id="searchInput" class="form-control search-bar" placeholder="Buscar na tela (Texto)...">
            </div>
        </div>
    </div>

    <div class="filter-bar">
        <form method="GET" class="row g-2 align-items-center" id="filterForm">
            <div class="col-md-3">
                <label class="form-label small fw-bold text-secondary mb-1">Empresa:</label>
                <select name="empresa" class="form-select form-select-sm" onchange="this.form.submit()">
                    <option value="">Todas as Empresas</option>
                    <?php foreach ($lista_empresas_unicas as $emp_nome): ?>
                        <option value="<?php echo htmlspecialchars($emp_nome); ?>" <?php echo ($filtro_empresa === $emp_nome) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($emp_nome); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label small fw-bold text-secondary mb-1">Sentido:</label>
                <select name="sentido" class="form-select form-select-sm" onchange="this.form.submit()">
                    <option value="">Todos os Sentidos</option>
                    <option value="ida" <?php echo ($filtro_sentido === 'ida') ? 'selected' : ''; ?>>➡️ IDA</option>
                    <option value="volta" <?php echo ($filtro_sentido === 'volta') ? 'selected' : ''; ?>>⬅️ VOLTA</option>
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label small fw-bold text-secondary mb-1">Status (Tempo Real):</label>
                <select id="filtroStatusJS" class="form-select form-select-sm" onchange="aplicarFiltrosFrontend()">
                    <option value="">Todos</option>
                    <option value="atrasado_geral">🚨 Atrasados (Qualquer Tipo)</option>
                    <option value="atrasado_saida">⚠️ Atraso na Saída (Inicial)</option>
                    <option value="atrasado_percurso">🛑 Atraso no Percurso (GPS)</option>
                    <option value="pontual">✅ Pontual</option>
                    <option value="desligado">🔌 Desligado / Sem Sinal</option>
                    <option value="aguardando">⏳ Aguardando</option>
                </select>
            </div>
            <?php if (!empty($filtro_empresa) || !empty($filtro_sentido)): ?>
            <div class="col-auto align-self-end">
                <a href="/" class="btn btn-outline-danger btn-sm mb-1"><i class="bi bi-x-circle me-1"></i>Limpar</a>
            </div>
            <?php endif; ?>
        </form>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-md-2"><div class="card-summary card-blue"><h5>Total</h5><h3 id="count-total">0</h3></div></div>
        <div class="col-md-2"><div class="card-summary card-red"><h5>Atrasados</h5><h3 id="count-atrasados">0</h3></div></div>
        <div class="col-md-2"><div class="card-summary card-green"><h5>Pontual</h5><h3 id="count-pontual">0</h3></div></div>
        <div class="col-md-2"><div class="card-summary bg-secondary"><h5>Desligados</h5><h3 id="count-desligados">0</h3></div></div>
        <div class="col-md-2"><div class="card-summary bg-info"><h5>Em Deslocamento</h5><h3 id="count-deslocamento">0</h3></div></div>
        <div class="col-md-2"><div class="card-summary bg-warning"><h5>Não Iniciou</h5><h3 id="count-sem-inicio">0</h3></div></div>
    </div>

    <div class="card border-0 shadow-sm">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover table-sm table-ultra-compact align-middle mb-0">
                    <thead>
                        <tr>
                            <th onclick="ordenarTabela(0)">Empresa <i class="bi bi-arrow-down-up small"></i></th>
                            <th onclick="ordenarTabela(1)">Rota <i class="bi bi-arrow-down-up small"></i></th>
                            <th onclick="ordenarTabela(2)">Veículo <i class="bi bi-arrow-down-up small"></i></th>
                            <th class="col-narrow" title="Previsão Início"> Prev. Ini</th>
                            <th onclick="ordenarTabela(4)">Prog. Início <i class="bi bi-arrow-down-up small"></i></th>
                            <th onclick="ordenarTabela(5)">Real Início <i class="bi bi-arrow-down-up small"></i></th>
                            <th onclick="ordenarTabela(6)">Prog. Fim <i class="bi bi-arrow-down-up small"></i></th>
                            <th class="col-narrow" title="Previsão Fim">Prev. Fim</th>
                            <th onclick="ordenarTabela(8)">Ultimo Reporte <i class="bi bi-arrow-down-up small"></i></th>
                            <th onclick="ordenarTabela(9)">Status <i class="bi bi-arrow-down-up small"></i></th>
                            <th class="text-center">Prev. Inicial</th>
                            <th class="text-center">Prev. Final</th>
                        </tr>
                    </thead>
                    <tbody id="tabela-veiculos">
                        <?php renderizar_linhas_tabela($todas_linhas, $filtro_empresa, $filtro_sentido, $hora_atual); ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="debugModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header bg-dark text-white"><h5 class="modal-title">🐛 JSON 1º Veículo</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div>
      <div class="modal-body"><textarea class="form-control" rows="15" style="font-family: monospace; font-size: 0.8rem;"><?php echo $primeiro_veiculo_json; ?></textarea></div>
    </div>
  </div>
</div>

<div class="modal fade" id="popupResultado" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-lg">
    <div class="modal-content">
      <div class="modal-header border-0 pb-0"><h5 class="modal-title fw-bold text-primary"><i class="bi bi-map me-2"></i>Detalhamento da Rota</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <div class="modal-body p-0">
        <div id="resultadoConteudo" class="px-3 pt-2"></div>
        <div id="mapaRota"></div>
        <div class="d-flex justify-content-center gap-3 py-2 small bg-light border-top">
            <span class="d-flex align-items-center"><i class="bi bi-circle-fill me-1"></i> Rota Percorrida</span>
            <span class="d-flex align-items-center"><i class="bi bi-circle-fill text-danger me-1"></i> Rota Fixa (Oficial)</span>
            <span class="d-flex align-items-center"><i class="bi bi-circle-fill text-primary me-1"></i> Previsão Futura</span>
        </div>
      </div>
    </div>
  </div>
</div>

<div class="modal fade" id="alertModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-lg">
    <div class="modal-content shadow-lg border-0 rounded-4">
      <div class="modal-header border-0 pb-0"><h5 class="modal-title fw-bold text-danger">⚠️ Alerta de Atrasos</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <div class="modal-body pt-3" id="alertModalBody"></div>
      <div class="modal-footer border-0"><button type="button" class="btn btn-secondary rounded-pill px-4" data-bs-dismiss="modal">Ciente</button></div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script src="https://unpkg.com/leaflet-routing-machine@3.2.12/dist/leaflet-routing-machine.js"></script>

<script>
let mapaInstancia = null;
let routingControlFuture = null; 
let direcaoAtual = {};
let currentController = null; 
let renderToken = 0;

function mostrarDebug() { new bootstrap.Modal(document.getElementById('debugModal')).show(); }

document.addEventListener("DOMContentLoaded", function() {
    const modalElement = document.getElementById('popupResultado');
    modalElement.addEventListener('shown.bs.modal', function () { if (mapaInstancia) mapaInstancia.invalidateSize(); });
    modalElement.addEventListener('hidden.bs.modal', function () {
        if (currentController) { currentController.abort(); currentController = null; }
        renderToken++; limparMapaSeguro();
    });

    const searchInput = document.getElementById('searchInput');
    let timeoutSearch = null;
    if (searchInput) {
        searchInput.addEventListener('keyup', function() {
            clearTimeout(timeoutSearch);
            timeoutSearch = setTimeout(aplicarFiltrosFrontend, 300); // Debounce
        });
    }

    verificarAlertas();
    carregarPrevisoesAutomaticamente();
    iniciarAtualizacaoAutomatica();
    atualizarCardsResumo(); 
    
    // Toggle Sidebar
    const btnToggle = document.getElementById('btnToggleMenu');
    const sidebar = document.getElementById('sidebar');
    const content = document.getElementById('content');
    if (btnToggle) {
        btnToggle.addEventListener('click', function() {
            sidebar.classList.toggle('toggled');
            content.classList.toggle('toggled');
            setTimeout(() => { if (mapaInstancia) mapaInstancia.invalidateSize(); }, 350);
        });
    }
});

function aplicarFiltrosFrontend() {
    const termoBusca = document.getElementById('searchInput')?.value.toLowerCase() || '';
    const statusFiltro = document.getElementById('filtroStatusJS')?.value || '';
    const linhas = document.querySelectorAll("#tabela-veiculos tr");
    const celulasParaCalcular = [];

    linhas.forEach(linha => {
        if (linha.cells.length < 10) return; // Ignora linha de "Nenhum resultado"
        
        const textoLinha = linha.textContent.toLowerCase();
        const matchTexto = !termoBusca || textoLinha.includes(termoBusca);
        
        let matchStatus = true;
        if (statusFiltro) {
            const textoStatus = linha.cells[9].innerText.toLowerCase();
            if (statusFiltro === 'atrasado_geral') matchStatus = textoStatus.includes('atrasado');
            else if (statusFiltro === 'atrasado_saida') matchStatus = textoStatus.includes('(inicial)') || textoStatus.includes('(p. inicial)');
            else if (statusFiltro === 'atrasado_percurso') matchStatus = textoStatus.includes('(percurso)');
            else if (statusFiltro === 'pontual') matchStatus = textoStatus.includes('pontual');
            else if (statusFiltro === 'desligado') matchStatus = textoStatus.includes('desligado');
            else if (statusFiltro === 'aguardando') matchStatus = textoStatus.includes('aguardando');
        }

        if (matchTexto && matchStatus) {
            linha.style.display = "";
            const celulaPrev = linha.querySelector('.celula-previsao');
            if (celulaPrev && celulaPrev.innerText.includes('--:--')) celulasParaCalcular.push(celulaPrev);
        } else {
            linha.style.display = "none";
        }
    });

    atualizarCardsResumo();
    if (celulasParaCalcular.length > 0) carregarPrevisoesAutomaticamente(celulasParaCalcular);
}

function revisarStatusTimeBased() {
    const linhas = document.querySelectorAll("#tabela-veiculos tr");
    const agora = new Date();
    const horaAtualStr = String(agora.getHours()).padStart(2, '0') + ":" + String(agora.getMinutes()).padStart(2, '0');
    let houveMudanca = false;

    linhas.forEach(row => {
        if(row.cells.length < 10) return;
        const statusCell = row.cells[9];
        const progInicio = row.cells[4].innerText.trim();
        const realInicio = row.cells[5].innerText.trim();
        const textoStatus = statusCell.innerText.trim();

        if ((realInicio === 'N/D' || realInicio === '') && !textoStatus.includes('Desligado')) {
            const diff = calcularDiferencaMinutos(progInicio, horaAtualStr);
            if (diff > 10) {
                if (!textoStatus.includes('Atrasado')) {
                    statusCell.innerHTML = '<span class="badge rounded-pill bg-danger blink-animation">Atrasado (Inicial)</span>';
                    houveMudanca = true;
                }
            } else if (!textoStatus.includes('Aguardando')) {
                statusCell.innerHTML = '<span class="badge bg-light text-dark border">Aguardando</span>';
                houveMudanca = true;
            }
        }
    });
    if (houveMudanca) { aplicarFiltrosFrontend(); atualizarCardsResumo(); }
}

function iniciarAtualizacaoAutomatica() {
    revisarStatusTimeBased();
    setInterval(async () => {
        try {
            // OTIMIZAÇÃO: Busca apenas o TBODY, não a página toda.
            const urlAtual = new URL(window.location.href);
            urlAtual.searchParams.set('t', Date.now());
            urlAtual.searchParams.set('ajax_tbody', '1'); // Flag especial
            
            const response = await fetch(urlAtual);
            if(response.ok) {
                const novoTbodyHTML = await response.text();
                const tbodyAtual = document.getElementById('tabela-veiculos');
                if (tbodyAtual && tbodyAtual.innerHTML !== novoTbodyHTML) {
                    tbodyAtual.innerHTML = novoTbodyHTML;
                    carregarPrevisoesAutomaticamente();
                    aplicarFiltrosFrontend();
                } else {
                    revisarStatusTimeBased();
                }
            }
        } catch (e) { console.error("Erro refresh auto", e); }
        
        // Refresh visual dos badges
        document.querySelectorAll('.celula-previsao:not(:empty)').forEach(celula => {
            const est = celula.innerText.trim();
            if(est !== '--:--' && est !== 'N/D') atualizarStatusBadge(celula, est, celula.getAttribute('data-prog-fim'));
        });
    }, 30000);
}

function limparMapaSeguro() {
    if (mapaInstancia) {
        try { mapaInstancia.off(); mapaInstancia.remove(); } catch(e) { console.warn("Erro ao limpar mapa", e); }
        mapaInstancia = null;
    }
    const divMapa = document.getElementById("mapaRota");
    if(divMapa) divMapa.innerHTML = "";
}

async function processarBusca(placa, localAlvo, horarioFinalProg, idLinha, button, tipo) {
    if (currentController) currentController.abort();
    currentController = new AbortController();
    const signal = currentController.signal;
    renderToken = Date.now();
    const meuToken = renderToken;

    const previsaoCell = button.closest('td'); 
    const textoOriginal = previsaoCell.innerHTML;
    previsaoCell.innerHTML = '<div class="spinner-border spinner-border-sm text-primary"></div>';
    
    limparMapaSeguro(); 
    document.getElementById("resultadoConteudo").innerHTML = `<div class="text-center py-5"><div class="spinner-border text-primary mb-3" style="width: 3rem; height: 3rem;" role="status"></div><p class="text-muted fw-bold">Buscando dados...</p><small class="text-muted">Linha ID: ${idLinha}</small></div>`;
    new bootstrap.Modal(document.getElementById("popupResultado")).show();

    try {
        const baseUrl = tipo === 'inicial' ? `/previsaoinicial/${placa}` : `/previsao/${placa}`;
        const [respRastreio, respRota] = await Promise.all([
            fetch(`/buscar_rastreamento/${placa}`, { signal }), 
            fetch(`${baseUrl}?idLinha=${idLinha}`, { signal }) 
        ]);
        
        if (renderToken !== meuToken) return;
        const data = await respRastreio.json();
        const rotaData = await respRota.json();
        previsaoCell.innerHTML = textoOriginal;

        let latVeiculo = null, lngVeiculo = null;
        let veiculoData = (Array.isArray(data) && data.length > 0) ? data[0] : null;
        
        if (veiculoData) {
            if (veiculoData.lat) { latVeiculo = veiculoData.lat; lngVeiculo = veiculoData.lng; }
            else if (veiculoData.loc) { 
                const p = (typeof veiculoData.loc === 'string') ? veiculoData.loc.split(',') : veiculoData.loc;
                latVeiculo = p[0]; lngVeiculo = p[1];
            }
            
            const enderecoAtual = veiculoData.endereco || veiculoData.loc || 'Endereço não identificado';
            let horarioEstimado = '--';
            if (rotaData.duracaoSegundos) {
                 const chegada = new Date(new Date().getTime() + rotaData.duracaoSegundos * 1000);
                 horarioEstimado = String(chegada.getHours()).padStart(2, '0') + ":" + String(chegada.getMinutes()).padStart(2, '0');
            }

            // Atualiza células na tabela principal se necessário
            if (tipo === 'final' && horarioEstimado !== '--') {
                const cell = document.getElementById('prev-fim-' + placa);
                if (cell) {
                    cell.innerText = horarioEstimado;
                    cell.className = (horarioFinalProg !== 'N/D' && horarioEstimado > horarioFinalProg) ? 'fw-bold text-danger' : 'fw-bold text-success';
                    atualizarStatusBadge(cell, horarioEstimado, horarioFinalProg);
                }
            } else if (tipo === 'inicial' && horarioEstimado !== '--') {
                const cellIni = document.getElementById('prev-ini-' + placa);
                if (cellIni) { cellIni.innerText = horarioEstimado; cellIni.className = 'fw-bold text-info small'; }
            }

            const labelProg = (tipo === 'inicial') ? 'Inicial Programado' : 'Final Programado';
            const labelEst = (tipo === 'inicial') ? 'Chegada Prevista' : 'Previsão Atualizada';
            const statusCor = (horarioEstimado !== '--' && horarioFinalProg !== 'N/D' && horarioEstimado > horarioFinalProg) ? 'text-danger fw-bold' : (horarioEstimado !== '--' ? 'text-success fw-bold' : 'text-dark');
            
            document.getElementById("resultadoConteudo").innerHTML = `
            <div class="container-fluid px-3 pt-3">
                <div class="d-flex justify-content-between align-items-center mb-3 p-2 border rounded bg-light">
                    <h5 class="mb-0 fw-bold text-dark"><i class="bi bi-bus-front me-2 text-primary"></i>${veiculoData.identificacao || 'Veículo'}</h5>
                    <span class="badge bg-success">Online</span> <span class="badge bg-secondary ms-2 small">Rota ID: ${idLinha}</span>
                </div>
                <div class="row g-2 mb-3">
                    <div class="col-6"><div class="p-3 border rounded bg-white shadow-sm h-100"><small class="text-uppercase text-secondary fw-bold" style="font-size:0.7rem">Origem</small><br><span id="txt-origem" class="d-block text-dark fw-semibold" style="font-size: 0.9rem;">${enderecoAtual}</span></div></div>
                    <div class="col-6"><div class="p-3 border rounded bg-white shadow-sm h-100"><small class="text-uppercase text-secondary fw-bold" style="font-size:0.7rem">Destino</small><br><span id="txt-destino" class="d-block text-dark fw-semibold" style="font-size: 0.9rem;">${localAlvo || 'N/D'}</span></div></div>
                </div>
                <div class="row g-2 mb-3">
                    <div class="col-6"><div class="p-3 border rounded bg-light shadow-sm h-100 text-center"><small class="text-uppercase text-secondary fw-bold" style="font-size:0.7rem">${labelProg}</small><br><span class="d-block text-dark fw-semibold fs-4">${horarioFinalProg || 'N/D'}</span></div></div>
                    <div class="col-6"><div class="p-3 border rounded shadow-sm h-100 text-center" style="background-color: #f0f8ff;"><small class="text-uppercase text-secondary fw-bold" style="font-size:0.7rem">${labelEst}</small><br><span class="d-block fs-4 ${statusCor}">${horarioEstimado}</span></div></div>
                </div>
                <div class="d-flex justify-content-between align-items-center mb-3 p-2 border rounded bg-light" style="background-color: #e0f2fe;">
                    <strong class="text-dark"><i class="bi bi-stopwatch-fill me-2"></i>Estimativa:</strong>
                    <div class="text-end"><span class="fs-4 fw-bold text-dark">${rotaData.tempo || '--'}</span><span class="text-muted mx-2">|</span><span class="fs-5 text-dark">${rotaData.distancia || '--'}</span></div>
                </div>
            </div>`;
            
            if (latVeiculo && lngVeiculo) {
                gerarMapaRota(latVeiculo, lngVeiculo, rotaData.lat, rotaData.lng, (veiculoData?.endereco || 'Veículo'), localAlvo, rotaData.waypoints_usados, rotaData.todos_pontos_visual, rotaData.rastro_oficial, rotaData.rastro_real, tipo, meuToken);
            } else { 
                document.getElementById("mapaRota").innerHTML = `<div class="text-center text-muted py-5">Coordenadas indisponíveis.</div>`; 
            }
        } else {
            document.getElementById("resultadoConteudo").innerHTML = `<div class="alert alert-warning m-3 text-dark">Veículo não encontrado.</div>`;
        }
    } catch (err) {
        if (err.name !== 'AbortError') {
            previsaoCell.innerHTML = textoOriginal;
            document.getElementById("resultadoConteudo").innerHTML = `<div class='alert alert-danger m-3'>Erro: ${err.message}</div>`;
        }
    }
}

async function processarEmLotes(items, limite, callback) {
    let index = 0;
    const executar = async () => {
        if (index >= items.length) { atualizarCardsResumo(); return; }
        const lote = Array.from(items).slice(index, index + limite);
        index += limite;
        await Promise.all(lote.map(item => callback(item)));
        await executar();
    };
    await executar();
}

function atualizarCardsResumo() {
    let counts = { total: 0, atrasados: 0, pontual: 0, desligados: 0, deslocamento: 0, semInicio: 0 };
    document.querySelectorAll("#tabela-veiculos tr").forEach(row => {
        if (row.style.display === 'none' || row.cells.length < 10) return;
        counts.total++;
        const text = row.cells[9].innerText.trim();
        if (text.includes("Atrasado")) {
            if (text.includes("(Inicial)") && !text.includes("Percurso")) counts.semInicio++; 
            else counts.atrasados++;
        } else if (text.includes("Pontual")) counts.pontual++;
        else if (text.includes("Desligado")) counts.desligados++;
        else if (text.includes("Em deslocamento")) counts.deslocamento++;
        else counts.semInicio++;
    });
    const setVal = (id, val) => { const el = document.getElementById(id); if(el) el.innerText = val; };
    setVal('count-total', counts.total); setVal('count-atrasados', counts.atrasados); setVal('count-pontual', counts.pontual);
    setVal('count-desligados', counts.desligados); setVal('count-deslocamento', counts.deslocamento); setVal('count-sem-inicio', counts.semInicio);
}

function calcularDiferencaMinutos(hB, hC) {
    if (!hB || !hC || hB === 'N/D' || hC === 'N/D' || hC === '--:--') return 0;
    const [bH, bM] = hB.split(':').map(Number);
    const [cH, cM] = hC.split(':').map(Number);
    return (cH * 60 + cM) - (bH * 60 + bM);
}

function atualizarStatusBadge(celula, horarioEstimado, horarioProgramado) {
    const tr = celula.closest('tr');
    if (!tr) return;
    const statusCell = tr.cells[9];
    if (!statusCell || statusCell.innerText.trim() === 'Desligado') return;

    const progInicio = tr.cells[4].innerText.trim();
    const realInicio = tr.cells[5].innerText.trim();
    const tolerancia = 10;
    let htmlBadge = '';

    if (realInicio === 'N/D' || realInicio === '') {
        const diffInicio = calcularDiferencaMinutos(progInicio, new Date().toTimeString().substr(0,5));
        htmlBadge = (diffInicio > 10) ? '<span class="badge rounded-pill bg-danger blink-animation">Atrasado (Inicial)</span>' : '<span class="badge bg-light text-dark border">Aguardando</span>';
    } else {
        const diffSaida = calcularDiferencaMinutos(progInicio, realInicio);
        const diffChegada = calcularDiferencaMinutos(horarioProgramado, horarioEstimado);
        const sentidoIda = tr.getAttribute('data-sentido-ida') === 'true';

        if (sentidoIda) {
            if (diffChegada > tolerancia) htmlBadge = (diffSaida > tolerancia) ? '<span class="badge bg-danger rounded-pill">Atrasado (P. Inicial)</span>' : '<span class="badge bg-danger rounded-pill">Atrasado (Percurso)</span>';
            else htmlBadge = (diffSaida < -tolerancia) ? '<span class="badge bg-info text-dark rounded-pill">Pontual (Ini. Adiantado)</span>' : (diffSaida > tolerancia) ? '<span class="badge bg-warning text-dark rounded-pill">Pontual (Ini. Atrasado)</span>' : '<span class="badge bg-success rounded-pill">Pontual</span>';
        } else {
            htmlBadge = (diffSaida > tolerancia) ? '<span class="badge bg-danger rounded-pill">Atrasado (Percurso)</span>' : '<span class="badge bg-success rounded-pill">Pontual</span>';
        }
    }
    if (statusCell.innerHTML !== htmlBadge) { statusCell.innerHTML = htmlBadge; aplicarFiltrosFrontend(); }
}

async function carregarPrevisoesAutomaticamente(elementosPrioritarios = null) {
    const celulas = elementosPrioritarios || document.querySelectorAll('.celula-previsao');
    await processarEmLotes(celulas, 5, async (celula) => {
        const progFim = celula.getAttribute('data-prog-fim');
        const tsCache = celula.getAttribute('data-ts-cache');
        if (celula.getAttribute('data-calculando') === 'true' || (celula.innerText.trim() !== '--:--' && celula.innerText.trim() !== 'N/D')) return;

        if (tsCache) {
            const dateCache = new Date(parseInt(tsCache) * 1000);
            const horarioCache = String(dateCache.getHours()).padStart(2,'0') + ":" + String(dateCache.getMinutes()).padStart(2,'0');
            celula.innerText = horarioCache;
            celula.className = (progFim !== 'N/D' && horarioCache > progFim) ? 'fw-bold text-danger celula-previsao' : 'fw-bold text-success celula-previsao';
            atualizarStatusBadge(celula, horarioCache, progFim);
            return;
        }
        
        if (celula.getAttribute('data-calcular') === 'true') {
            celula.setAttribute('data-calculando', 'true');
            celula.innerHTML = '<div class="spinner-border spinner-border-sm text-secondary mini-loader"></div>';
            try {
                const response = await fetch(`/previsao/${celula.getAttribute('data-placa')}?idLinha=${celula.getAttribute('data-id-linha')}`);
                const data = await response.json();
                if (data.duracaoSegundos) {
                    const chegada = new Date(new Date().getTime() + data.duracaoSegundos * 1000);
                    const est = String(chegada.getHours()).padStart(2,'0') + ":" + String(chegada.getMinutes()).padStart(2,'0');
                    celula.innerText = est;
                    celula.className = (progFim !== 'N/D' && est > progFim) ? 'fw-bold text-danger celula-previsao' : 'fw-bold text-success celula-previsao';
                    atualizarStatusBadge(celula, est, progFim);
                } else celula.innerText = 'N/D';
            } catch (error) { celula.innerText = 'Erro'; } 
            finally { celula.removeAttribute('data-calculando'); }
        }
    });
}

function ordenarTabela(n) {
    const tbody = document.getElementById("tabela-veiculos");
    const linhas = Array.from(tbody.rows);
    const asc = !direcaoAtual[n];
    direcaoAtual[n] = asc;
    linhas.sort((a, b) => {
        const cellA = a.cells[n]?.innerText.trim().toLowerCase() || '';
        const cellB = b.cells[n]?.innerText.trim().toLowerCase() || '';
        const numA = parseFloat(cellA.replace(',', '.'));
        const numB = parseFloat(cellB.replace(',', '.'));
        if (!isNaN(numA) && !isNaN(numB)) return asc ? numA - numB : numB - numA;
        return asc ? cellA.localeCompare(cellB) : cellB.localeCompare(cellA);
    });
    linhas.forEach(linha => tbody.appendChild(linha));
}

function verificarAlertas() {
    let clientesCriticos = {};
    document.querySelectorAll("#tabela-veiculos tr").forEach(linha => {
        if (linha.getAttribute("data-atraso-tipo") === "saida") {
            const empresa = linha.cells[0].innerText.trim(); 
            const rota = linha.cells[1].innerText.trim();
            const veiculo = linha.cells[2].innerText.trim();
            const horario = linha.cells[3].innerText.trim();
            if (!clientesCriticos[empresa]) clientesCriticos[empresa] = [];
            clientesCriticos[empresa].push({ veiculo, rota, horario });
        }
    });
    const total = Object.values(clientesCriticos).flat().length;
    if (total > 0) {
        let html = `<div class="alert alert-danger border-danger d-flex align-items-center mb-4"><i class="bi bi-megaphone-fill fs-3 me-3 text-danger"></i><div><strong>Saída Atrasada!</strong><br><b>${total}</b> veículo(s) atrasados.</div></div>`;
        for (const [cliente, lista] of Object.entries(clientesCriticos)) {
            html += `<h6 class="fw-bold mt-3 mb-2 text-dark">${cliente} (${lista.length})</h6><div class="list-group list-group-flush border rounded mb-4">` + lista.map(i => `<div class="list-group-item d-flex justify-content-between px-3 py-2"><div><span class="badge bg-dark me-2">${i.veiculo}</span><strong>${i.rota}</strong><br><small class="text-danger">Prog: ${i.horario}</small></div></div>`).join('') + `</div>`;
        }
        document.getElementById('alertModalBody').innerHTML = html;
        new bootstrap.Modal(document.getElementById('alertModal')).show();
    }
}

function gerarMapaRota(latO, lngO, latD, lngD, nomeO, nomeD, waypoints, todosPontos=[], rastroOficial=[], rastroReal=[], tipo, tokenSolicitante) {
    if (tokenSolicitante !== renderToken) return;
    limparMapaSeguro();
    latO = parseFloat(latO)|| -23.5505; lngO = parseFloat(lngO)|| -46.6333;
    latD = parseFloat(latD)|| latO; lngD = parseFloat(lngD)|| lngO;

    try { mapaInstancia = L.map('mapaRota').setView([latO, lngO], 13); } catch (e) { return; }
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { attribution: '© OpenStreetMap' }).addTo(mapaInstancia);

    const iconBase = {iconSize:[25,41],iconAnchor:[12,41],popupAnchor:[1,-34],shadowSize:[41,41]};
    const icons = {
        bus: L.icon({iconUrl:'https://cdn-icons-png.flaticon.com/512/3448/3448339.png',iconSize:[38,38],iconAnchor:[19,38],popupAnchor:[0,-35]}),
        red: L.icon({...iconBase, iconUrl:'https://raw.githubusercontent.com/pointhi/leaflet-color-markers/master/img/marker-icon-red.png'}),
        green: L.icon({...iconBase, iconUrl:'https://raw.githubusercontent.com/pointhi/leaflet-color-markers/master/img/marker-icon-green.png'}),
        stopBlack: L.icon({iconUrl:'https://raw.githubusercontent.com/pointhi/leaflet-color-markers/master/img/marker-icon-black.png',iconSize:[18,29],iconAnchor:[9,29],popupAnchor:[1,-25]}),
        stopBlue: L.icon({iconUrl:'https://raw.githubusercontent.com/pointhi/leaflet-color-markers/master/img/marker-icon-blue.png',iconSize:[18,29],iconAnchor:[9,29],popupAnchor:[1,-25]})
    };

    let boundsTotal = L.latLngBounds();
    boundsTotal.extend([latO, lngO]);

    if (rastroOficial?.length) L.polyline(rastroOficial.map(c=>[c[1],c[0]]), {color:'#ff0505',weight:6,opacity:0.6}).addTo(mapaInstancia);
    
    let pontosReais = Array.isArray(rastroReal) ? rastroReal : (rastroReal?.coords || []);
    if (pontosReais.length > 0) {
        let line = L.polyline([...pontosReais.map(c=>[c[1],c[0]]), [latO,lngO]], {color:'#000',weight:5,opacity:0.6,dashArray:'1,6'}).addTo(mapaInstancia);
        boundsTotal.extend(line.getBounds());
    }

    let pointsFuture = [L.latLng(latO, lngO)];
    if (tipo === 'final' && waypoints?.length) waypoints.slice(1,-1).forEach(w => pointsFuture.push(L.latLng(w[1], w[0])));
    pointsFuture.push(L.latLng(latD, lngD));

    if (tokenSolicitante !== renderToken) return;

    routingControlFuture = L.Routing.control({
        waypoints: pointsFuture,
        lineOptions: { styles: [{color: '#0d6efd', opacity: 0.4, weight: 6}] },
        createMarker: function(i, wp, n) {
            if (i === 0) return L.marker(wp.latLng, {icon: icons.bus, zIndexOffset:1000}).bindPopup('<b>🚌 Veículo em Movimento</b>');
            if (i === n - 1) return L.marker(wp.latLng, {icon: (tipo==='inicial'?icons.red:icons.green), zIndexOffset:900}).bindPopup(tipo==='inicial'?'<b>🚩 Ponto Inicial (Destino)</b>':'<b>🏁 Destino Final</b>');
            return null; 
        },
        addWaypoints: false, draggableWaypoints: false, show: false, fitSelectedRoutes: false
    }).addTo(mapaInstancia);

    if (todosPontos?.length) {
        todosPontos.forEach((p, i) => {
            if (i === 0) L.marker([p.lat, p.lng], {icon: icons.red}).addTo(mapaInstancia).bindPopup(`<b>🚩 Ponto Inicial</b><br>${p.nome}`);
            else if (tipo === 'final' && i === todosPontos.length-1) L.marker([p.lat, p.lng], {icon: icons.green}).addTo(mapaInstancia).bindPopup(`<b>🏁 Destino Final</b><br>${p.nome}`);
            else if (tipo === 'final') L.marker([p.lat, p.lng], {icon: p.passou?icons.stopBlack:icons.stopBlue}).addTo(mapaInstancia).bindPopup(`<b>🚏 ${p.nome}</b><br><span class="badge ${p.passou?'bg-dark':'bg-primary'}">${p.passou?'Já passou':'Próxima parada'}</span>`);
        });
    }

    routingControlFuture.on('routesfound', function(e) {
        if (tokenSolicitante !== renderToken) return;
        const r = e.routes[0];
        if (r.instructions?.length) {
            const origem = r.instructions[0].road;
            const dest = [...r.instructions].reverse().find(i=>i.road?.trim())?.road;
            if(origem && document.getElementById('txt-origem')) document.getElementById('txt-origem').innerHTML = `<b>${origem}</b> <br><small class='text-muted'>Ref: ${document.getElementById('txt-origem').innerText}</small>`;
            if(dest && document.getElementById('txt-destino')) document.getElementById('txt-destino').innerHTML = `<b>${dest}</b> <br><small class='text-muted'>Ref: ${document.getElementById('txt-destino').innerText}</small>`;
        }
        boundsTotal.extend(L.polyline(r.coordinates).getBounds());
        mapaInstancia.fitBounds(boundsTotal, {padding:[50,50], maxZoom:16, animate:true});
    });
    setTimeout(() => { if (mapaInstancia && boundsTotal.isValid()) mapaInstancia.fitBounds(boundsTotal, {padding:[50,50], maxZoom:15}); }, 500);
}

function buscarRastreamento(p,l,h,i,b) { processarBusca(p,l,h,i,b,'final'); }
function buscarRastreamentoinicial(p,l,h,i,b) { processarBusca(p,l,h,i,b,'inicial'); }
</script>
</body>
</html>
