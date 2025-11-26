<?php
// Verifica se a sessão já existe
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

// --- CONFIGURAÇÃO ---
$empresas_permitidas = $_SESSION['allowed_companies'] ?? [];
date_default_timezone_set('America/Sao_Paulo'); 
$hora_atual = date('H:i');

// Carga inicial dos dados
$dados_dashboard = handle_index_data($empresas_permitidas);
extract($dados_dashboard); // Extrai $todas_linhas, $qtd_total, etc.

// --- LÓGICA DE FILTROS (PHP) ---
// 1. Extrair lista única de Empresas para o Select
$lista_empresas_unicas = [];

if (!empty($todas_linhas)) {
    foreach ($todas_linhas as $l) {
        // Coleta Empresa
        $nome_empresa = $l['empresa']['nome'] ?? '';
        if (!empty($nome_empresa)) {
            $lista_empresas_unicas[$nome_empresa] = $nome_empresa;
        }
    }
    asort($lista_empresas_unicas); // Ordena alfabeticamente
}

// 2. Capturar seleção do usuário via GET
$filtro_empresa = $_GET['empresa'] ?? '';
$filtro_sentido = $_GET['sentido'] ?? ''; // 'ida' ou 'volta'

// Debug (Opcional)
$primeiro_veiculo_json = json_encode($todas_linhas[0] ?? null);
?>

<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ABM Bus - Dashboard</title>
    
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <link rel="stylesheet" href="https://unpkg.com/leaflet-routing-machine@3.2.12/dist/leaflet-routing-machine.css" />

    <style>
        body { background-color: #f8f9fa; font-family: 'Segoe UI', sans-serif; }
        .sidebar { background-color: #0b1f3a; color: white; min-height: 100vh; width: 250px; position: fixed; z-index: 1000; transition: all 0.3s; }
        .sidebar a { color: #d1d5db; display: block; padding: 14px 20px; text-decoration: none; border-left: 4px solid transparent; font-weight: 500; }
        .sidebar a.active, .sidebar a:hover { background-color: #1b2e52; color: white; border-left-color: #0d6efd; }
        .content { margin-left: 250px; padding: 30px; }
        .card-summary { border-radius: 12px; padding: 20px; color: white; text-align: center; position: relative; overflow: hidden; box-shadow: 0 4px 6px rgba(0,0,0,0.1); }
        .card-summary h5 { font-size: 0.9rem; opacity: 0.9; margin-bottom: 5px; text-transform: uppercase; }
        .card-summary h3 { font-size: 2rem; font-weight: 700; margin: 0; }
        .card-blue { background: linear-gradient(135deg, #0b1f3a 0%, #1e3a8a 100%); }
        .card-red { background: linear-gradient(135deg, #b91c1c 0%, #dc2626 100%); }
        .card-green { background: linear-gradient(135deg, #047857 0%, #10b981 100%); }
        .bg-secondary { background: linear-gradient(135deg, #4b5563 0%, #6b7280 100%) !important; }
        .bg-info { background: linear-gradient(135deg, #0891b2 0%, #06b6d4 100%) !important; }
        .bg-warning { background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%) !important; }
        .table-responsive { background: white; border-radius: 12px; box-shadow: 0 2px 10px rgba(0,0,0,0.05); padding: 5px; }
        .table thead { background-color: #f1f5f9; }
        .table thead th { color: #475569; font-weight: 600; border-bottom: 2px solid #e2e8f0; padding: 15px; cursor: pointer; white-space: nowrap; }
        .table tbody td { padding: 15px; vertical-align: middle; color: #334155; white-space: normal; }
        th:hover { background-color: #e2e8f0; color: #0f172a; }
        .search-bar { border-radius: 20px; border: 1px solid #cbd5e1; padding-left: 40px; height: 45px; }
        .search-icon { position: absolute; left: 15px; top: 12px; color: #94a3b8; }
        .modal-content { border: none; border-radius: 16px; overflow: hidden; }
        #mapaRota { height: 550px; width: 100%; background-color: #e9ecef; z-index: 1; }
        .leaflet-routing-container { display: none !important; }
        .mini-loader { width: 1rem; height: 1rem; border-width: 0.15em; }
        .table-ultra-compact { font-size: 0.9rem; }
        .table-ultra-compact th, .table-ultra-compact td { padding-top: 2px !important; padding-bottom: 2px !important; padding-left: 4px !important; padding-right: 4px !important; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 150px; }
        .col-narrow { width: 1%; white-space: nowrap; }
        .bi { font-size: 0.9em; }
        .btn-xs { padding: 0.1rem 0.3rem; font-size: 0.7rem; line-height: 1.0; }
        .blink-animation { animation: blinker 1.5s linear infinite; }
        @keyframes blinker { 50% { opacity: 0.5; } }
        /* Estilo para a barra de filtros */
        .filter-bar { background-color: #fff; border-radius: 12px; padding: 15px; margin-bottom: 20px; box-shadow: 0 2px 5px rgba(0,0,0,0.05); }
    </style>
</head>
<body>

<div class="sidebar d-flex flex-column">
    <div class="text-center py-4 bg-dark bg-opacity-25">
        <img src="https://viacaomimo.com.br/wp-content/uploads/2023/07/Background-12-1.png" alt="Logo" style="max-width: 160px;">
    </div>
    <a href="#" class="active"><i class="bi bi-speedometer2 me-2"></i>Dashboard</a>
    <a href="#"><i class="bi bi-map me-2"></i>Rotas</a>
    <a href="#"><i class="bi bi-bus-front me-2"></i>Veículos</a>
    <a href="#"><i class="bi bi-person-vcard me-2"></i>Motoristas</a>
    <a href="relatorio.php"><i class="bi bi-file-earmark-text me-2"></i>Relatórios</a>
    <?php if (($_SESSION['user_role'] ?? '') === 'admin'): ?>
        <a href="admin.php"><i class="bi bi-people-fill me-2"></i>Usuários</a>
    <?php endif; ?>
    <a href="logout.php" class="mt-auto text-danger border-top border-secondary"><i class="bi bi-box-arrow-right me-2"></i>Sair</a>
</div>

<div class="content">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h4 class="fw-bold text-dark mb-1">Visão Geral da Frota</h4>
            <p class="text-muted small mb-0">Monitoramento em tempo real</p>
        </div>
        <div class="d-flex gap-2 w-50 justify-content-end">
          <?php if (($_SESSION['user_role'] ?? '') === 'admin'): ?>
            <button class="btn btn-sm btn-dark" onclick="mostrarDebug()">
                <i class="bi bi-bug"></i> Debug
            </button>
            <?php endif; ?>
            <div class="position-relative w-50">
                <i class="bi bi-search search-icon"></i>
                <input type="text" id="searchInput" class="form-control search-bar" placeholder="Buscar na tela...">
            </div>
        </div>
    </div>

    <div class="filter-bar">
        <form method="GET" class="row g-2 align-items-center">
            
            <div class="col-md-5">
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

            <div class="col-md-5">
                <label class="form-label small fw-bold text-secondary mb-1">Sentido:</label>
                <select name="sentido" class="form-select form-select-sm" onchange="this.form.submit()">
                    <option value="">Todos os Sentidos</option>
                    <option value="ida" <?php echo ($filtro_sentido === 'ida') ? 'selected' : ''; ?>>➡️ IDA</option>
                    <option value="volta" <?php echo ($filtro_sentido === 'volta') ? 'selected' : ''; ?>>⬅️ VOLTA</option>
                </select>
            </div>

            <?php if (!empty($filtro_empresa) || !empty($filtro_sentido)): ?>
            <div class="col-auto align-self-end">
                <a href="dashboard.php" class="btn btn-outline-danger btn-sm mb-1"><i class="bi bi-x-circle me-1"></i>Limpar</a>
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
    <?php 
    function diffMinutosPHP($h1, $h2) {
        if ($h1 == 'N/D' || $h2 == 'N/D') return 0;
        $t1 = strtotime($h1); $t2 = strtotime($h2);
        return ($t2 - $t1) / 60;
    }

    $contador_linhas_exibidas = 0;

    foreach ($todas_linhas as $linha): 
        
        // --- EXTRAÇÃO DO ID DA LINHA ---
        $id_linha = $linha['idLinha'] ?? '';

        // --- FILTRAGEM PHP ---
        
        // 1. Filtro de Empresa
        if (!empty($filtro_empresa) && ($linha['empresa']['nome'] ?? '') !== $filtro_empresa) {
            continue;
        }

        // 2. Filtro de Sentido
        $sIdaRaw = $linha['sentidoIDA'] ?? $linha['sentidoIda'] ?? true;
        $sentido_ida_bool = filter_var($sIdaRaw, FILTER_VALIDATE_BOOLEAN);
        $sentido_string = $sentido_ida_bool ? 'ida' : 'volta';

        if (!empty($filtro_sentido) && $sentido_string !== $filtro_sentido) {
            continue;
        }

        $contador_linhas_exibidas++;

        // --- PREPARAÇÃO DE DADOS ---
        $prog = $linha['horarioProgramado'] ?? '23:59';
        $real = $linha['horarioReal'] ?? 'N/D';
        $sentido_ida_attr = $sentido_ida_bool ? 'true' : 'false';
        
        $icon_sentido = $sentido_ida_bool 
            ? '<i class="bi bi-arrow-right-circle-fill text-primary ms-1" title="IDA"></i>' 
            : '<i class="bi bi-arrow-left-circle-fill text-warning ms-1" title="VOLTA"></i>';

        $status_html = '<span class="badge bg-light text-dark border">Aguardando</span>';
        $atraso_saida = false;

        if (($linha['categoria'] ?? '') == 'Carro desligado') {
             $status_html = '<span class="badge bg-secondary rounded-pill">Desligado</span>';
        }
        elseif ($real == 'N/D' || empty($real)) {
             $diff = diffMinutosPHP($prog, $hora_atual);
             if ($diff > 10) {
                 $atraso_saida = true;
                 $status_html = '<span class="badge rounded-pill bg-danger blink-animation">Atrasado (Inicial)</span>';
             } else {
                 $status_html = '<span class="badge bg-light text-dark border">Aguardando</span>';
             }
        }
        else {
             $status_html = '<span class="badge bg-success rounded-pill">Pontual</span>';
        }

        // Adicionando ID da linha no atributo data
        $tr_attr = ($atraso_saida ? 'data-atraso-tipo="saida"' : '') . ' data-sentido-ida="' . $sentido_ida_attr . '" data-id-linha="' . htmlspecialchars($id_linha) . '"';
        
        $placa_clean = htmlspecialchars($linha['veiculo']['veiculo'] ?? '', ENT_QUOTES);
        $id_prev_fim = "prev-fim-" . $placa_clean;
        $id_prev_ini = "prev-ini-" . $placa_clean;
        $data_placa = $placa_clean;
        $data_prog_fim = htmlspecialchars($linha['horariofinalProgramado'] ?? 'N/D', ENT_QUOTES);
        
        $timestamp_cache = $linha['previsao_fim_ts'] ?? ''; 
        $tem_cache = !empty($timestamp_cache);
        $ja_saiu = ($real != 'N/D');
        $deve_calcular = ($ja_saiu && $linha['categoria'] != 'Carro desligado' && !$tem_cache) ? 'true' : 'false';

        $valor_cache = $tem_cache ? date('H:i', $timestamp_cache) : '--:--';
        
        $classe_prev = "text-muted";
        if ($tem_cache && $data_prog_fim != 'N/D') {
             $classe_prev = ($valor_cache > $data_prog_fim) ? "text-danger fw-bold" : "text-success fw-bold";
        }
    ?>
        <tr <?php echo $tr_attr; ?>>
            <td><?php echo htmlspecialchars($linha['empresa']['nome'] ?? 'N/D'); ?></td>
            <td>
                <?php echo htmlspecialchars($linha['descricaoLinha'] ?? 'N/D'); ?>
                <?php echo $icon_sentido; ?>
            </td>
            <td class="fw-bold text-primary"><?php echo $placa_clean; ?></td>
            <td id="<?php echo $id_prev_ini; ?>" class="text-muted small">--:--</td>
            <td class="<?php echo $atraso_saida ? 'text-danger fw-bold' : ''; ?>">
                <?php echo htmlspecialchars($linha['horarioProgramado'] ?? 'N/D'); ?>
            </td>
            <td><?php echo htmlspecialchars($linha['horarioReal'] ?? 'N/D'); ?></td>
            <td><strong><?php echo htmlspecialchars($linha['horariofinalProgramado'] ?? 'N/D'); ?></strong></td>
            
             <td id="<?php echo $id_prev_fim; ?>" 
                class="<?php echo $classe_prev; ?> celula-previsao" 
                data-placa="<?php echo $data_placa; ?>"
                data-prog-fim="<?php echo $data_prog_fim; ?>"
                data-ts-cache="<?php echo $timestamp_cache; ?>" 
                data-calcular="<?php echo $deve_calcular; ?>"
                data-id-linha="<?php echo htmlspecialchars($id_linha); ?>">
                <?php echo $valor_cache; ?>
            </td>

            <td title="<?php echo htmlspecialchars($linha['ultimaData'] ?? ''); ?>"><?php echo htmlspecialchars($linha['ultimaData'] ?? 'N/D'); ?></td>
            <td>
                <?php echo $status_html; ?>
            </td>
             <td class="text-center">
                <button class="btn btn-outline-primary btn-xs rounded-circle" title="Prev. Inicial"
                    onclick="buscarRastreamentoinicial('<?php echo $placa_clean; ?>', '<?php echo htmlspecialchars($linha['localinicial'] ?? 'N/D', ENT_QUOTES); ?>', '<?php echo htmlspecialchars($linha['horarioProgramado'] ?? 'N/D', ENT_QUOTES); ?>', '<?php echo htmlspecialchars($id_linha); ?>', this)">
                    <i class="bi bi-clock"></i>
                </button>
            </td>
            <td class="text-center">
                <button class="btn btn-primary btn-sm rounded-circle shadow-sm" 
                    onclick="buscarRastreamento('<?php echo $placa_clean; ?>', '<?php echo htmlspecialchars($linha['localfinal'] ?? 'N/D', ENT_QUOTES); ?>', '<?php echo htmlspecialchars($linha['horariofinalProgramado'] ?? 'N/D', ENT_QUOTES); ?>', '<?php echo htmlspecialchars($id_linha); ?>', this)">
                    <i class="bi bi-geo-alt-fill"></i>
                </button>
            </td>
        </tr>
    <?php endforeach; ?>
    <?php if ($contador_linhas_exibidas === 0): ?>
        <tr><td colspan="12" class="text-center py-4 text-muted">Nenhum veículo encontrado com os filtros selecionados.</td></tr>
    <?php endif; ?>
</tbody>
</table>
</div></div></div></div>

<div class="modal fade" id="debugModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header bg-dark text-white">
        <h5 class="modal-title">🐛 JSON 1º Veículo</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <textarea class="form-control" rows="15" style="font-family: monospace; font-size: 0.8rem;"><?php echo $primeiro_veiculo_json; ?></textarea>
      </div>
    </div>
  </div>
</div>

<div class="modal fade" id="popupResultado" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-lg">
    <div class="modal-content">
      <div class="modal-header border-0 pb-0">
        <h5 class="modal-title fw-bold text-primary"><i class="bi bi-map me-2"></i>Detalhamento da Rota</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body p-0">
        <div id="resultadoConteudo" class="px-3 pt-2"></div>
        <div id="mapaRota"></div>
        <div class="d-flex justify-content-center gap-3 py-2 small bg-light border-top">
            <span class="d-flex align-items-center"><i class="bi bi-circle-fill text-danger me-1"></i> Rota Percorrida</span>
            <span class="d-flex align-items-center"><i class="bi bi-circle-fill text-primary me-1"></i> Rota Futura</span>
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
let routingControlPast = null;
let direcaoAtual = {};

// --- CONTROLE DE CONCORRÊNCIA ---
let currentController = null; // Para cancelar requisições HTTP
let renderToken = 0;          // Para cancelar desenhos de mapa antigos

function mostrarDebug() {
    new bootstrap.Modal(document.getElementById('debugModal')).show();
}

document.addEventListener("DOMContentLoaded", function() {
    const modalElement = document.getElementById('popupResultado');
    modalElement.addEventListener('shown.bs.modal', function () {
        if (mapaInstancia) mapaInstancia.invalidateSize();
    });

    const searchInput = document.getElementById('searchInput');
    if (searchInput) {
        searchInput.addEventListener('keyup', function() {
            const termo = this.value.toLowerCase();
            const linhas = document.querySelectorAll("table tbody tr");
            linhas.forEach(function(linha) {
                const textoLinha = linha.textContent.toLowerCase();
                linha.style.display = textoLinha.includes(termo) ? "" : "none";
            });
        });
    }

    verificarAlertas();
    carregarPrevisoesAutomaticamente();
    iniciarAtualizacaoAutomatica();
    atualizarCardsResumo(); 
});

function iniciarAtualizacaoAutomatica() {
    setInterval(async () => {
        try {
            const urlAtual = new URL(window.location.href);
            urlAtual.searchParams.set('t', Date.now());
            // Adicionado signal para não travar se o usuário navegar
            const controllerAuto = new AbortController();
            const response = await fetch(urlAtual, { signal: controllerAuto.signal });
            const text = await response.text();
            const parser = new DOMParser();
            const doc = parser.parseFromString(text, 'text/html');

            const novoTbody = doc.getElementById('tabela-veiculos');
            const tbodyAtual = document.getElementById('tabela-veiculos');
            if (novoTbody && tbodyAtual && tbodyAtual.innerHTML !== novoTbody.innerHTML) {
                tbodyAtual.innerHTML = novoTbody.innerHTML;
                carregarPrevisoesAutomaticamente();
                atualizarCardsResumo(); 
            }
        } catch (e) { 
            if (e.name !== 'AbortError') console.error("Erro refresh auto", e); 
        }
    }, 30000);
}
    
const modalElement = document.getElementById('popupResultado');
if(modalElement){
    modalElement.addEventListener('hidden.bs.modal', function () {
        // Ao fechar, cancela qualquer busca pendente para economizar rede
        if (currentController) {
            currentController.abort();
            currentController = null;
        }
        renderToken++; // Invalida qualquer desenho pendente
        limparMapaSeguro();
    });
}

function limparMapaSeguro() {
    if (mapaInstancia) {
        try {
            mapaInstancia.off();
            mapaInstancia.remove();
        } catch(e) { console.warn("Erro ao limpar mapa", e); }
        mapaInstancia = null;
    }
    const divMapa = document.getElementById("mapaRota");
    if(divMapa) divMapa.innerHTML = "";
}

// --- FUNÇÃO DE BUSCA OTIMIZADA COM ID DA LINHA ---
// --- FUNÇÃO DE BUSCA OTIMIZADA COM ID DA LINHA ---
async function processarBusca(placa, localAlvo, horarioFinalProg, idLinha, button, tipo) {
    // 1. Cancelamento de requisições anteriores
    if (currentController) {
        currentController.abort();
    }
    currentController = new AbortController();
    const signal = currentController.signal;
    
    // 2. Token de renderização
    const meuToken = Date.now();
    renderToken = meuToken;

    const previsaoCell = button.closest('td'); 
    const textoOriginal = previsaoCell.innerHTML;
    
    // Feedback visual
    previsaoCell.innerHTML = '<div class="spinner-border spinner-border-sm text-primary"></div>';
    
    limparMapaSeguro(); 
    document.getElementById("resultadoConteudo").innerHTML = `<div class="text-center py-5"><div class="spinner-border text-primary mb-3" style="width: 3rem; height: 3rem;" role="status"></div><p class="text-muted fw-bold">Buscando dados...</p><small class="text-muted">Linha ID: ${idLinha}</small></div>`;
    
    new bootstrap.Modal(document.getElementById("popupResultado")).show();

    try {
        // --- CORREÇÃO AQUI: Adicionamos ?idLinha=... na URL ---
        const baseUrl = tipo === 'inicial' ? `/previsaoinicial/${placa}` : `/previsao/${placa}`;
        const urlPrevisao = `${baseUrl}?idLinha=${idLinha}`;
        
        // 3. Executa as requisições em paralelo
        const [respRastreio, respRota] = await Promise.all([
            fetch(`/buscar_rastreamento/${placa}`, { signal }), // Rastreamento geralmente é só por placa mesmo
            fetch(urlPrevisao, { signal }) // Previsão agora leva o ID da linha específica
        ]);
        
        if (renderToken !== meuToken) return; 

        const data = await respRastreio.json();
        const rotaData = await respRota.json();
        
        previsaoCell.innerHTML = textoOriginal;

        if (renderToken !== meuToken) return;

        let latVeiculo = null, lngVeiculo = null;
        let latDestino = null, lngDestino = null;
        let veiculoData = (Array.isArray(data) && data.length > 0) ? data[0] : null;
        
        if (veiculoData) {
            // Lógica de extração de coordenadas (igual ao anterior)
            if (veiculoData.lat) { latVeiculo = veiculoData.lat; lngVeiculo = veiculoData.lng; }
            else if (veiculoData.loc) { if (typeof veiculoData.loc === 'string') { const p = veiculoData.loc.split(','); latVeiculo = p[0]; lngVeiculo = p[1]; } else if (Array.isArray(veiculoData.loc)) { latVeiculo = veiculoData.loc[0]; lngVeiculo = veiculoData.loc[1]; } }
            
            const enderecoAtual = veiculoData.endereco || veiculoData.loc || 'Endereço não identificado';
            let horarioEstimado = '--';
            
            if (rotaData.duracaoSegundos) {
                 const agora = new Date();
                 const chegada = new Date(agora.getTime() + rotaData.duracaoSegundos * 1000);
                 const h = String(chegada.getHours()).padStart(2, '0');
                 const m = String(chegada.getMinutes()).padStart(2, '0');
                 horarioEstimado = `${h}:${m}`;
            }

            // Atualiza células na tabela
            if (tipo === 'final' && horarioEstimado !== '--') {
                const cell = document.getElementById('prev-fim-' + placa);
                if (cell) {
                    cell.innerText = horarioEstimado;
                    cell.className = (horarioFinalProg !== 'N/D' && horarioEstimado > horarioFinalProg) ? 'fw-bold text-danger' : 'fw-bold text-success';
                    atualizarStatusBadge(cell, horarioEstimado, horarioFinalProg);
                }
            }
            if (tipo === 'inicial' && horarioEstimado !== '--') {
                const cellIni = document.getElementById('prev-ini-' + placa);
                if (cellIni) {
                    cellIni.innerText = horarioEstimado;
                    cellIni.className = 'fw-bold text-info small';
                }
            }

            const labelProg = (tipo === 'inicial') ? 'Inicial Programado' : 'Final Programado';
            const labelEst = (tipo === 'inicial') ? 'Chegada Prevista' : 'Previsão Atualizada';
            const statusCor = (horarioEstimado !== '--' && horarioFinalProg !== 'N/D' && horarioEstimado > horarioFinalProg) ? 'text-danger fw-bold' : (horarioEstimado !== '--' ? 'text-success fw-bold' : 'text-dark');
            const tituloDestino = tipo === 'inicial' ? 'Ponto Inicial' : 'Ponto Final';
            
            document.getElementById("resultadoConteudo").innerHTML = `
            <div class="container-fluid px-3 pt-3">
                <div class="d-flex justify-content-between align-items-center mb-3 p-2 border rounded bg-light">
                    <h5 class="mb-0 fw-bold text-dark"><i class="bi bi-bus-front me-2 text-primary"></i>${veiculoData.identificacao || 'Veículo'}</h5>
                    <span class="badge bg-success">Online</span>
                    <span class="badge bg-secondary ms-2 small">Rota ID: ${idLinha}</span>
                </div>
                <div class="row g-2 mb-3">
                    <div class="col-6"><div class="p-3 border rounded bg-white shadow-sm h-100"><small class="text-uppercase text-secondary fw-bold" style="font-size:0.7rem">Origem</small><br><span id="txt-origem" class="d-block text-dark fw-semibold" style="font-size: 0.9rem;">${enderecoAtual}</span></div></div>
                    <div class="col-6"><div class="p-3 border rounded bg-white shadow-sm h-100"><small class="text-uppercase text-secondary fw-bold" style="font-size:0.7rem">Destino (${tituloDestino})</small><br><span id="txt-destino" class="d-block text-dark fw-semibold" style="font-size: 0.9rem;">${localAlvo || 'N/D'}</span></div></div>
                </div>
                <div class="row g-2 mb-3">
                    <div class="col-6"><div class="p-3 border rounded bg-light shadow-sm h-100 text-center"><small class="text-uppercase text-secondary fw-bold" style="font-size:0.7rem">${labelProg}</small><br><span class="d-block text-dark fw-semibold fs-4">${horarioFinalProg || 'N/D'}</span></div></div>
                    <div class="col-6"><div class="p-3 border rounded shadow-sm h-100 text-center" style="background-color: #f0f8ff;"><small class="text-uppercase text-secondary fw-bold" style="font-size:0.7rem">${labelEst}</small><br><span class="d-block fs-4 ${statusCor}">${horarioEstimado}</span></div></div>
                </div>
                <div class="d-flex justify-content-between align-items-center mb-3 p-2 border rounded bg-light" style="background-color: #e0f2fe;">
                    <strong class="text-dark" style="font-size: 1.1rem;"><i class="bi bi-stopwatch-fill me-2"></i>Estimativa:</strong>
                    <div class="text-end"><span class="fs-4 fw-bold text-dark" id="info-tempo">${rotaData.tempo || '--'}</span><span class="text-muted mx-2">|</span><span class="fs-5 text-dark" id="info-distancia">${rotaData.distancia || '--'}</span></div>
                </div>
            </div>`;
            
            if (rotaData.lat) { latDestino = rotaData.lat; lngDestino = rotaData.lng; }
            if (latVeiculo && lngVeiculo) {
                gerarMapaRota(latVeiculo, lngVeiculo, latDestino, lngDestino, (veiculoData?.endereco || 'Veículo'), localAlvo, rotaData.waypoints_usados, rotaData.todos_pontos_visual, tipo, meuToken);
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
// ... As outras funções auxiliares (processarEmLotes, etc.) permanecem idênticas ...

async function processarEmLotes(items, limite, callback) {
    let index = 0;
    const executar = async () => {
        if (index >= items.length) {
            atualizarCardsResumo(); 
            return;
        }
        const lote = Array.from(items).slice(index, index + limite);
        index += limite;
        const promessas = lote.map(item => callback(item));
        await Promise.all(promessas);
        await executar();
    };
    await executar();
}

function atualizarCardsResumo() {
    let counts = { total: 0, atrasados: 0, pontual: 0, desligados: 0, deslocamento: 0, semInicio: 0 };
    const linhas = document.querySelectorAll("table tbody tr");
    
    linhas.forEach(row => {
        if (row.style.display === 'none') return; 

        counts.total++;
        const statusCell = row.cells[9];
        if (statusCell) {
            const text = statusCell.innerText.trim();
            if (text.includes("Atrasado")) {
                if (text.includes("(Inicial)") && !text.includes("Percurso")) {
                    counts.semInicio++; 
                } else {
                    counts.atrasados++;
                }
            } else if (text.includes("Pontual")) {
                counts.pontual++;
            } else if (text.includes("Desligado")) {
                counts.desligados++;
            } else if (text.includes("Em deslocamento")) {
                counts.deslocamento++;
            } else if (text.includes("Aguardando")) {
                counts.semInicio++;
            } else {
                counts.semInicio++;
            }
        }
    });

    const setVal = (id, val) => {
        const el = document.getElementById(id);
        if(el) el.innerText = val;
    };
    setVal('count-total', counts.total);
    setVal('count-atrasados', counts.atrasados);
    setVal('count-pontual', counts.pontual);
    setVal('count-desligados', counts.desligados);
    setVal('count-deslocamento', counts.deslocamento);
    setVal('count-sem-inicio', counts.semInicio);
}

function calcularDiferencaMinutos(horaBase, horaComparacao) {
    if (!horaBase || !horaComparacao || horaBase === 'N/D' || horaComparacao === 'N/D' || horaComparacao === '--:--') {
        return 0;
    }
    const [hB, mB] = horaBase.split(':').map(Number);
    const [hC, mC] = horaComparacao.split(':').map(Number);
    return (hC * 60 + mC) - (hB * 60 + mB);
}

function getHoraAtual() {
    const now = new Date();
    return String(now.getHours()).padStart(2,'0') + ":" + String(now.getMinutes()).padStart(2,'0');
}

function atualizarStatusBadge(celula, horarioEstimado, horarioProgramado) {
    const tr = celula.closest('tr');
    if (!tr) return;
    
    const sentidoIda = tr.getAttribute('data-sentido-ida') === 'true';
    const statusCell = tr.cells[9];
    if (!statusCell) return;

    const textoAtual = statusCell.innerText.trim();
    if (textoAtual === 'Desligado') return; 

    const progInicio = tr.cells[4].innerText.trim();
    const realInicio = tr.cells[5].innerText.trim();
    
    const tolerancia = 10; 
    let htmlBadge = '';

    if (realInicio === 'N/D' || realInicio === '') {
        const agora = getHoraAtual();
        const diffInicio = calcularDiferencaMinutos(progInicio, agora);
        if (diffInicio > 10) {
            htmlBadge = '<span class="badge rounded-pill bg-danger blink-animation">Atrasado (Inicial)</span>';
        } else {
            htmlBadge = '<span class="badge bg-light text-dark border">Aguardando</span>';
        }
        statusCell.innerHTML = htmlBadge;
        return; 
    }

    const diffSaida = calcularDiferencaMinutos(progInicio, realInicio);
    const diffChegada = calcularDiferencaMinutos(horarioProgramado, horarioEstimado);

    if (sentidoIda) {
        if (diffChegada > tolerancia) {
            if (diffSaida > tolerancia) {
                htmlBadge = '<span class="badge bg-danger rounded-pill">Atrasado (P. Inicial)</span>';
            } else {
                htmlBadge = '<span class="badge bg-danger rounded-pill">Atrasado (Percurso)</span>';
            }
        } else {
            if (diffSaida < -tolerancia) {
                htmlBadge = '<span class="badge bg-info text-dark rounded-pill">Pontual (Ini. Adiantado)</span>';
            } else if (diffSaida > tolerancia) {
                htmlBadge = '<span class="badge bg-warning text-dark rounded-pill">Pontual (Ini. Atrasado)</span>';
            } else {
                htmlBadge = '<span class="badge bg-success rounded-pill">Pontual</span>';
            }
        }
    } else {
        if (diffSaida > tolerancia) {
            htmlBadge = '<span class="badge bg-danger rounded-pill">Atrasado (Percurso)</span>';
        } else {
            htmlBadge = '<span class="badge bg-success rounded-pill">Pontual</span>';
        }
    }

    statusCell.innerHTML = htmlBadge;
}

async function carregarPrevisoesAutomaticamente() {
    const celulas = document.querySelectorAll('.celula-previsao');
    await processarEmLotes(celulas, 5, async (celula) => {
        const progFim = celula.getAttribute('data-prog-fim');
        const tsCache = celula.getAttribute('data-ts-cache');
        const deveCalcular = celula.getAttribute('data-calcular');
        
        if (tsCache) {
            const dateCache = new Date(parseInt(tsCache) * 1000);
            const h = String(dateCache.getHours()).padStart(2, '0');
            const m = String(dateCache.getMinutes()).padStart(2, '0');
            const horarioCache = `${h}:${m}`;
            celula.innerText = horarioCache;
            celula.className = (progFim !== 'N/D' && horarioCache > progFim) ? 'fw-bold text-danger celula-previsao' : 'fw-bold text-success celula-previsao';
            atualizarStatusBadge(celula, horarioCache, progFim);
            return; 
        }
        
        if (deveCalcular === 'true') {
            const placa = celula.getAttribute('data-placa');
            // --- CORREÇÃO: PEGAR O ID DA LINHA ---
            const idLinha = celula.getAttribute('data-id-linha'); 
            
            celula.innerHTML = '<div class="spinner-border spinner-border-sm text-secondary mini-loader"></div>';
            try {
                // --- CORREÇÃO: ENVIAR O ID NA URL ---
                const response = await fetch(`/previsao/${placa}?idLinha=${idLinha}`);
                const data = await response.json();
                if (data.duracaoSegundos) {
                    const agora = new Date();
                    const chegada = new Date(agora.getTime() + data.duracaoSegundos * 1000);
                    const h = String(chegada.getHours()).padStart(2, '0');
                    const m = String(chegada.getMinutes()).padStart(2, '0');
                    const est = `${h}:${m}`;
                    celula.innerText = est;
                    celula.className = (progFim !== 'N/D' && est > progFim) ? 'fw-bold text-danger celula-previsao' : 'fw-bold text-success celula-previsao';
                    atualizarStatusBadge(celula, est, progFim);
                } else { celula.innerText = 'N/D'; }
            } catch (error) { celula.innerText = 'Erro'; }
        }
    });
}

function ordenarTabela(n) {
    const tabela = document.querySelector("table tbody");
    const linhas = Array.from(tabela.rows);
    const asc = !direcaoAtual[n];
    direcaoAtual[n] = asc;
    linhas.sort((a, b) => {
        const cellA = a.cells[n].innerText.trim().toLowerCase();
        const cellB = b.cells[n].innerText.trim().toLowerCase();
        const numA = parseFloat(cellA.replace(',', '.'));
        const numB = parseFloat(cellB.replace(',', '.'));
        if (!isNaN(numA) && !isNaN(numB)) { return asc ? numA - numB : numB - numA; }
        return asc ? cellA.localeCompare(cellB) : cellB.localeCompare(cellA);
    });
    linhas.forEach(linha => tabela.appendChild(linha));
}

function verificarAlertas() {
    let clientesCriticos = {};
    const linhas = document.querySelectorAll("table tbody tr");
    linhas.forEach(linha => {
        if (linha.getAttribute("data-atraso-tipo") === "saida") {
            const empresa = linha.cells[0].innerText.trim(); 
            const rota = linha.cells[1].innerText.trim();
            const veiculo = linha.cells[2].innerText.trim();
            const horario = linha.cells[3].innerText.trim();
            if (!clientesCriticos[empresa]) clientesCriticos[empresa] = [];
            clientesCriticos[empresa].push({ veiculo, rota, horario });
        }
    });
    const totalLinhasCriticas = Object.values(clientesCriticos).flat().length;
    if (totalLinhasCriticas > 0) {
        const modalBody = document.getElementById('alertModalBody');
        if (modalBody) {
            let html = `<div class="alert alert-danger border-danger d-flex align-items-center mb-4">
                <i class="bi bi-megaphone-fill fs-3 me-3 text-danger"></i>
                <div><strong>Saída Atrasada!</strong><br><b>${totalLinhasCriticas}</b> veículo(s) atrasados.</div></div>`;
            for (const [cliente, linhasCriticas] of Object.entries(clientesCriticos)) {
                html += `<h6 class="fw-bold mt-3 mb-2 text-dark">${cliente} (${linhasCriticas.length})</h6><div class="list-group list-group-flush border rounded mb-4">`;
                linhasCriticas.forEach(item => {
                    html += `<div class="list-group-item d-flex justify-content-between px-3 py-2"><div>
                        <span class="badge bg-dark me-2">${item.veiculo}</span><strong>${item.rota}</strong><br>
                        <small class="text-danger">Prog: ${item.horario}</small></div></div>`;
                });
                html += `</div>`;
            }
            modalBody.innerHTML = html;
            const modalAtraso = new bootstrap.Modal(document.getElementById('alertModal'));
            modalAtraso.show();
        }
    }
}

function gerarMapaRota(latOrigem, lngOrigem, latDestino, lngDestino, nomeOrigem, nomeDestino, waypointsRota, todosPontos = [], tipoDestino, tokenSolicitante) {
    if (tokenSolicitante !== renderToken) return;

    const latO = parseFloat(latOrigem) || -23.5505; 
    const lngO = parseFloat(lngOrigem) || -46.6333; 
    const latD = parseFloat(latDestino) || latO; 
    const lngD = parseFloat(lngDestino) || lngO;

    limparMapaSeguro();

    try { mapaInstancia = L.map('mapaRota').setView([latO, lngO], 13); } catch (e) { return; }
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { attribution: '© OpenStreetMap' }).addTo(mapaInstancia);

    var busIcon = L.icon({ iconUrl: 'https://cdn-icons-png.flaticon.com/512/3448/3448339.png', iconSize: [38, 38], iconAnchor: [19, 38], popupAnchor: [0, -35] });
    var redPinIcon = L.icon({ iconUrl: 'https://raw.githubusercontent.com/pointhi/leaflet-color-markers/master/img/marker-icon-red.png', iconSize: [25, 41], iconAnchor: [12, 41], popupAnchor: [1, -34], shadowSize: [41, 41] });
    var greenPinIcon = L.icon({ iconUrl: 'https://raw.githubusercontent.com/pointhi/leaflet-color-markers/master/img/marker-icon-green.png', iconSize: [25, 41], iconAnchor: [12, 41], popupAnchor: [1, -34], shadowSize: [41, 41] });
    var blackStopIcon = L.icon({ iconUrl: 'https://raw.githubusercontent.com/pointhi/leaflet-color-markers/master/img/marker-icon-black.png', iconSize: [18, 29], iconAnchor: [9, 29], popupAnchor: [1, -25], shadowSize: [29, 29] });
    var blueStopIcon = L.icon({ iconUrl: 'https://raw.githubusercontent.com/pointhi/leaflet-color-markers/master/img/marker-icon-blue.png', iconSize: [18, 29], iconAnchor: [9, 29], popupAnchor: [1, -25], shadowSize: [29, 29] });

    let pointsFuture = [L.latLng(latO, lngO)];
    if (tipoDestino === 'final') {
        if (waypointsRota && waypointsRota.length > 0) {
            for(let i = 1; i < waypointsRota.length - 1; i++) pointsFuture.push(L.latLng(waypointsRota[i][1], waypointsRota[i][0]));
        }
        pointsFuture.push(L.latLng(latD, lngD)); 
    } else { pointsFuture.push(L.latLng(latD, lngD)); }

    let pointsPast = [];
    if (todosPontos && todosPontos.length > 0) {
        if (tipoDestino === 'final') { 
            todosPontos.forEach((p) => { if (p.passou) pointsPast.push(L.latLng(p.lat, p.lng)); });
            const initialPoint = todosPontos[0];
            const isAtInitialPoint = (Math.abs(latO - initialPoint.lat) < 0.00001) && (Math.abs(lngO - initialPoint.lng) < 0.00001);
            if (pointsPast.length === 0 && !isAtInitialPoint) pointsPast.push(L.latLng(initialPoint.lat, initialPoint.lng));
            else if (pointsPast.length > 0) {
                const firstPastPoint = pointsPast[0];
                if (!isAtInitialPoint && (initialPoint.lat !== firstPastPoint.lat || initialPoint.lng !== firstPastPoint.lng)) pointsPast.unshift(L.latLng(initialPoint.lat, initialPoint.lng));
            }
        } else { 
             const initialPoint = todosPontos[0];
             const isAtInitialPoint = (Math.abs(latO - initialPoint.lat) < 0.00001) && (Math.abs(lngO - initialPoint.lng) < 0.00001);
             if (!isAtInitialPoint) pointsPast.push(L.latLng(initialPoint.lat, initialPoint.lng));
        }
    }
    pointsPast.push(L.latLng(latO, lngO));

    if (todosPontos && todosPontos.length > 0) {
        todosPontos.forEach((ponto, index) => {
            if (index === 0) L.marker([ponto.lat, ponto.lng], { icon: redPinIcon, zIndexOffset: 500 }).addTo(mapaInstancia).bindPopup(`<b>🚩 Ponto Inicial</b><br>${ponto.nome}`);
            if (tipoDestino === 'final' && index === todosPontos.length - 1) L.marker([ponto.lat, ponto.lng], { icon: greenPinIcon, zIndexOffset: 500 }).addTo(mapaInstancia).bindPopup(`<b>🏁 Destino Final</b><br>${ponto.nome}`);
            if (tipoDestino === 'final' && index > 0 && index < todosPontos.length - 1) {
                let stopIcon = ponto.passou ? blackStopIcon : blueStopIcon;
                let badgeClass = ponto.passou ? 'bg-dark' : 'bg-primary';
                L.marker([ponto.lat, ponto.lng], { icon: stopIcon, zIndexOffset: 400 }).addTo(mapaInstancia).bindPopup(`<b>🚏 ${ponto.nome}</b><br><span class="badge ${badgeClass}">${ponto.passou ? 'Já passou' : 'Próxima parada'}</span>`);
            }
        });
    }

    if (pointsPast.length >= 2) {
        routingControlPast = L.Routing.control({
            waypoints: pointsPast,
            lineOptions: { styles: [{color: '#343a40', opacity: 0.6, weight: 5, dashArray: '10, 10'}] },
            createMarker: function() { return null; }, 
            addWaypoints: false, draggableWaypoints: false, show: false, fitSelectedRoutes: false
        }).addTo(mapaInstancia);
    }

    if (tokenSolicitante !== renderToken) return;

    routingControlFuture = L.Routing.control({
        waypoints: pointsFuture,
        lineOptions: { styles: [{color: '#0d6efd', opacity: 0.8, weight: 6}] },
        createMarker: function(i, waypoint, n) {
            if (i === 0) return L.marker(waypoint.latLng, {icon: busIcon, zIndexOffset: 1000}).bindPopup('<b>🚌 Veículo em Movimento</b>');
            if (i === n - 1) {
                let iconFinal = (tipoDestino === 'inicial') ? redPinIcon : greenPinIcon;
                let popupFinal = (tipoDestino === 'inicial') ? '<b>🚩 Ponto Inicial (Destino)</b>' : '<b>🏁 Destino Final</b>';
                return L.marker(waypoint.latLng, {icon: iconFinal, zIndexOffset: 900}).bindPopup(popupFinal);
            }
            return null; 
        },
        addWaypoints: false, draggableWaypoints: false, show: false, fitSelectedRoutes: true
    }).addTo(mapaInstancia);

    routingControlFuture.on('routesfound', function(e) {
        if (tokenSolicitante !== renderToken) return;
        var routes = e.routes;
        if (routes && routes.length > 0) {
            const rota = routes[0];
            let nomeOrigem = null;
            let nomeDestino = null;
            if (rota.instructions && rota.instructions.length > 0) {
                nomeOrigem = rota.instructions[0].road;
                for (let i = rota.instructions.length - 1; i >= 0; i--) {
                    if (rota.instructions[i].road && rota.instructions[i].road.trim() !== "") {
                        nomeDestino = rota.instructions[i].road;
                        break;
                    }
                }
            }
            if (nomeOrigem && nomeOrigem.trim() !== "") {
                 const elOrigem = document.getElementById('txt-origem');
                 if (elOrigem) {
                     const textoAntigo = elOrigem.innerText; 
                     if (!textoAntigo.includes(nomeOrigem)) elOrigem.innerHTML = `<b>${nomeOrigem}</b> <br><small class='text-muted'>Ref: ${textoAntigo}</small>`;
                 }
            }
            if (nomeDestino && nomeDestino.trim() !== "") {
                 const elDestino = document.getElementById('txt-destino');
                 if (elDestino) {
                     const textoAntigoDest = elDestino.innerText;
                     if (!textoAntigoDest.includes(nomeDestino)) elDestino.innerHTML = `<b>${nomeDestino}</b> <br><small class='text-muted'>Ref: ${textoAntigoDest}</small>`;
                 }
            }
        }
    });
    routingControlFuture.on('routingerror', function(e) { console.warn("Erro rota:", e); });
}

async function buscarRastreamento(placa, localfinal, horarioFinalProg, idLinha, button) {
    processarBusca(placa, localfinal, horarioFinalProg, idLinha, button, 'final');
}

async function buscarRastreamentoinicial(placa, localinicial, horarioFinalProg, idLinha, button) {
    processarBusca(placa, localinicial, horarioFinalProg, idLinha, button, 'inicial'); 
}
</script>
</body>
</html>

