<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Relatório FullTrack > Supabase</title>
    <style>
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background-color: #121212; color: #e0e0e0; padding: 20px; }
        .container { max-width: 1000px; margin: 0 auto; background: #1e1e1e; padding: 30px; border-radius: 10px; border: 1px solid #333; }
        h1 { color: #3ecf8e; text-align: center; margin-bottom: 20px; } /* Verde Supabase */
        
        .progress-container { width: 100%; background-color: #333; border-radius: 25px; margin: 20px 0; overflow: hidden; }
        .progress-bar { height: 25px; width: 0%; background-color: #3ecf8e; text-align: center; line-height: 25px; color: #000; font-weight: bold; transition: width 0.3s; }
        
        .log-window { background-color: #000; color: #00ff00; font-family: 'Consolas', monospace; padding: 15px; height: 450px; overflow-y: scroll; border-radius: 5px; font-size: 13px; border: 1px solid #333; }
        .log-line { margin-bottom: 4px; border-bottom: 1px solid #222; padding-bottom: 2px; }
        
        .log-auth { color: #ff00ff; } 
        .log-db { color: #3ecf8e; font-weight: bold; } /* Cor Supabase */
        .log-cache { color: #00ffff; } 
        .log-error { color: #ff4444; }
    </style>
</head>
<body>

<div class="container">
    <h1>Integração FullTrack ➡️ Supabase</h1>
    
    <div class="progress-container">
        <div id="progressBar" class="progress-bar">0%</div>
    </div>
    <div id="statusText" style="text-align:center; margin-bottom: 10px; font-weight:bold;">Aguardando início...</div>
    <div class="log-window" id="logWindow"></div>
</div>

<script>
    function updateProgress(percent, text) {
        const bar = document.getElementById('progressBar');
        bar.style.width = percent + '%';
        bar.innerText = Math.floor(percent) + '%';
        if(text) document.getElementById('statusText').innerText = text;
    }
    function addLog(message, type = 'normal') {
        const logWindow = document.getElementById('logWindow');
        const div = document.createElement('div');
        div.className = 'log-line ' + (type === 'auth' ? 'log-auth' : (type === 'db' ? 'log-db' : (type === 'cache' ? 'log-cache' : (type === 'error' ? 'log-error' : ''))));
        div.innerText = `[${new Date().toLocaleTimeString()}] ${message}`;
        logWindow.appendChild(div);
        logWindow.scrollTop = logWindow.scrollHeight;
    }
    function finishProcess() {
        document.getElementById('statusText').innerText = "Sincronização com Supabase Concluída!";
        document.getElementById('statusText').style.color = "#3ecf8e";
    }
</script>

<?php
// --- CONFIGURAÇÕES ---
@apache_setenv('no-gzip', 1); @ini_set('implicit_flush', 1); set_time_limit(0); 

// 1. CONFIGURAÇÕES SUPABASE (PREENCHA AQUI)
$SB_HOST = "db.xxxxxxxxxxxxxx.supabase.co"; // Seu Host do Supabase
$SB_DB   = "postgres";
$SB_USER = "postgres";
$SB_PASS = "SuaSenhaDoBanco"; // Senha do banco (não é a chave da API)
$SB_PORT = "5432";

// 2. CONFIGURAÇÕES FULLTRACK
$ABM_USER = "lucas";
$ABM_PASS = "Lukinha2009";
$COOKIE_FILE = sys_get_temp_dir() . '/abm_session.txt';

// Tokens Geo
$LOCATION_TOKENS = [
    "pk.5dec6e778adac747992ee2564e7a57e1", "pk.74da1c80d1fdd103198bb2729dfc24b9",
    "pk.b499114f3bbfce0fae1e2894592036e6", "pk.3e95e7ff6651414b8b219ebca39f9309"
];

// --- FUNÇÕES PHP ---
function sendUpdate($script) { echo "<script>$script</script>"; echo str_pad('', 4096)."\n"; flush(); }
function sendLog($msg, $type='normal') { $c = addslashes($msg); sendUpdate("addLog('$c', '$type');"); }

// CONEXÃO PDO (PostgreSQL/Supabase)
function getDB() {
    global $SB_HOST, $SB_DB, $SB_USER, $SB_PASS, $SB_PORT;
    try {
        $dsn = "pgsql:host=$SB_HOST;port=$SB_PORT;dbname=$SB_DB;";
        $pdo = new PDO($dsn, $SB_USER, $SB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        return $pdo;
    } catch (PDOException $e) {
        sendLog("❌ Erro Conexão Supabase: " . $e->getMessage(), "error");
        exit;
    }
}

// CACHE NO SUPABASE
function buscarCacheSupabase($pdo, $lat, $lon) {
    // Busca arredondando para garantir match
    $stmt = $pdo->prepare("SELECT endereco_completo FROM cache_enderecos WHERE latitude = :lat AND longitude = :lon LIMIT 1");
    $stmt->execute([':lat' => $lat, ':lon' => $lon]);
    return $stmt->fetchColumn(); 
}

function salvarCacheSupabase($pdo, $lat, $lon, $endereco) {
    if (!$endereco || $endereco == "Endereço não encontrado") return;
    $sql = "INSERT INTO cache_enderecos (latitude, longitude, endereco_completo) VALUES (:lat, :lon, :end) ON CONFLICT (latitude, longitude) DO NOTHING";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':lat' => $lat, ':lon' => $lon, ':end' => $endereco]);
}

// --- FULLTRACK LOGIN & CURL --- (Mantido igual ao anterior)
function curl_req($metodo, $url, $cookie, $data=null, $headers=[]) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
    curl_setopt($ch, CURLOPT_COOKIEJAR, $cookie);
    curl_setopt($ch, CURLOPT_COOKIEFILE, $cookie);
    
    $defHeaders = [
        "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36",
        "Accept: application/json, text/javascript, */*; q=0.01"
    ];
    curl_setopt($ch, CURLOPT_HTTPHEADER, array_merge($defHeaders, $headers));

    if ($metodo == 'POST') {
        curl_setopt($ch, CURLOPT_POST, 1);
        if ($data) curl_setopt($ch, CURLOPT_POSTFIELDS, is_array($data)?http_build_query($data):$data);
    }
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $finalUrl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
    curl_close($ch);
    return [$resp, $code, $finalUrl];
}

function login() {
    global $ABM_USER, $ABM_PASS, $COOKIE_FILE;
    if (file_exists($COOKIE_FILE)) unlink($COOKIE_FILE);
    sendLog("🔑 Logando na FullTrack...", "auth");
    list($resp, $code, $url) = curl_req('POST', "https://abmtecnologia.abmprotege.net/emp/abmtecnologia", $COOKIE_FILE, 
        ["login"=>$ABM_USER, "senha"=>$ABM_PASS, "password"=>$ABM_PASS], ["Content-Type: application/x-www-form-urlencoded"]);
    
    if (strpos($url, "erro") !== false) return false;
    curl_req('GET', "https://abmtecnologia.abmprotege.net/mapaGeral", $COOKIE_FILE);
    return true;
}

// --- UTILS ---
function formatAddr($json) {
    $a = $json['address'] ?? [];
    $p = array_filter([$a['road']??'', $a['house_number']??'', $a['suburb']??'', $a['city']??'']);
    return implode(", ", $p) ?: "Endereço não encontrado";
}
function toFloat($v) { return floatval(preg_replace('/[^0-9\.-]/', '', str_replace(',', '.', (string)$v))); }

// === EXECUÇÃO ===
sendLog("🚀 Conectando ao Supabase...", "db");
$pdo = getDB();
sendLog("✅ Conexão DB estabelecida!", "db");

// 1. OBTER DADOS
$dt = new DateTime('yesterday');
$dtStr = $dt->format('d/m/Y');
sendLog("📡 Buscando infrações de $dtStr...", "normal");

// Login e Busca
if(!file_exists($COOKIE_FILE)) login();
list($resp, $code) = curl_req('POST', "https://api-fulltrack4.fulltrackapp.com/relatorio/DriverBehavior/gerar/", $COOKIE_FILE, [
    'id_cliente'=>'195577', 'id_motorista'=>'0', 'dt_inicial'=>"$dtStr 00:00:00", 'dt_final'=>"$dtStr 23:59:59",
    'id_indice'=>'7259', 'id_usuario'=>'250095', 'visualizar_por'=>'motorista'
], ["Authorization: Bearer a20cc5894d63a3eda08a1866c289b2c9b3ce2222"]);

if ($code != 200) {
    sendLog("⚠️ Sessão caiu. Relogando...", "auth");
    login();
    list($resp, $code) = curl_req('POST', "https://api-fulltrack4.fulltrackapp.com/relatorio/DriverBehavior/gerar/", $COOKIE_FILE, [
        'id_cliente'=>'195577', 'id_motorista'=>'0', 'dt_inicial'=>"$dtStr 00:00:00", 'dt_final'=>"$dtStr 23:59:59",
        'id_indice'=>'7259', 'id_usuario'=>'250095', 'visualizar_por'=>'motorista'
    ], ["Authorization: Bearer a20cc5894d63a3eda08a1866c289b2c9b3ce2222"]);
}

$json = json_decode($resp, true);
$allItems = [];

// Flatten Data
if ($json) {
    foreach ($json as $item) {
        if (!isset($item['sub_table'])) continue;
        foreach ($item['sub_table'] as $sub) {
            if (!isset($sub['sub_table_infracao'])) continue;
            foreach ($sub['sub_table_infracao'] as $type) {
                if(in_array($type['tipo_infracao'], ["Motor Ocioso", "Banguela"])) continue;
                foreach ($type['infracoes'] ?? [] as $det) {
                    $allItems[] = [
                        'ativo' => $item['descricao_ativo'],
                        'placa' => $item['tag_ativo'],
                        'motorista' => $sub['descricao_motorista'],
                        'tot_inf' => $item['total_infracoes'],
                        'tot_pen' => $item['total_penalidade'],
                        'tipo_inf' => $type['tipo_infracao'],
                        'id_ext' => $det['id_infracao'],
                        'data' => $det['data'],
                        'lat' => toFloat($det['endereco']['lat']),
                        'lon' => toFloat($det['endereco']['lon']),
                        'vel' => $det['velocidade'],
                        'pen' => $det['penalidade']
                    ];
                }
            }
        }
    }
}

$total = count($allItems);
sendLog("📋 Total de infrações: $total", "normal");

// 2. PROCESSAMENTO COM SUPABASE
$chunks = array_chunk($allItems, 4);
$done = 0;
$dbInserts = 0;

foreach ($chunks as $batch) {
    $mh = curl_multi_init();
    $handles = [];
    
    // A. Verifica Cache no Supabase
    foreach ($batch as $k => $row) {
        $addr = buscarCacheSupabase($pdo, $row['lat'], $row['lon']);
        if ($addr) {
            $batch[$k]['endereco'] = $addr;
            $batch[$k]['source'] = 'cache';
        } else {
            // Se não tem no banco, prepara API
            if ($row['lat'] == 0) {
                $batch[$k]['endereco'] = "N/A";
                $batch[$k]['source'] = 'invalid';
            } else {
                $tIdx = $k % count($LOCATION_TOKENS);
                $url = "https://us1.locationiq.com/v1/reverse?key=".$LOCATION_TOKENS[$tIdx]."&lat=".$row['lat']."&lon=".$row['lon']."&format=json";
                $ch = curl_init($url);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
                curl_setopt($ch, CURLOPT_TIMEOUT, 4);
                curl_multi_add_handle($mh, $ch);
                $handles[$k] = $ch;
                $batch[$k]['source'] = 'api';
            }
        }
    }
    
    // B. Executa API
    if ($handles) {
        $run = null; do { curl_multi_exec($mh, $run); } while ($run);
    }
    
    // C. Processa API e Salva no Banco
    foreach ($batch as $k => $row) {
        if ($row['source'] == 'api') {
            $ch = $handles[$k];
            if (curl_getinfo($ch, CURLINFO_HTTP_CODE) == 200) {
                $addr = formatAddr(json_decode(curl_multi_getcontent($ch), true));
                $batch[$k]['endereco'] = $addr;
                salvarCacheSupabase($pdo, $row['lat'], $row['lon'], $addr); // Salva no cache do banco
            } else {
                $batch[$k]['endereco'] = "Erro API";
            }
            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);
        }
        
        // INSERT PRINCIPAL (Upsert para não duplicar)
        // Convertendo data DD/MM/YYYY HH:mm:ss para formato DB YYYY-MM-DD
        $dtObj = DateTime::createFromFormat('d/m/Y H:i:s', $row['data']);
        $dtIso = $dtObj ? $dtObj->format('Y-m-d H:i:s') : null;

        $sql = "INSERT INTO relatorio_infracoes 
            (codigo_ativo, placa, motorista, total_infracoes_geral, total_penalidades_geral, tipo_infracao, id_infracao_externo, data_infracao, velocidade, penalidade_valor, latitude, longitude, endereco)
            VALUES 
            (:ativo, :placa, :moto, :ti, :tp, :tinf, :idext, :dt, :vel, :pen, :lat, :lon, :end)
            ON CONFLICT (id_infracao_externo) DO UPDATE SET endereco = EXCLUDED.endereco"; // Atualiza endereço se já existir
            
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':ativo' => $row['ativo'], ':placa' => $row['placa'], ':moto' => $row['motorista'],
            ':ti' => $row['tot_inf'], ':tp' => $row['tot_pen'], ':tinf' => $row['tipo_inf'],
            ':idext' => $row['id_ext'], ':dt' => $dtIso, ':vel' => intval($row['vel']),
            ':pen' => $row['pen'], ':lat' => $row['lat'], ':lon' => $row['lon'],
            ':end' => $batch[$k]['endereco']
        ]);
        $done++;
    }
    if($handles) curl_multi_close($mh);
    
    $pct = ($done / $total) * 100;
    sendUpdate("updateProgress($pct, 'Salvando no Supabase... $done / $total');");
    
    // Log visual a cada 8
    if($done % 8 == 0) sendLog("💾 Lote salvo no banco.", "db");
}

sendUpdate("finishProcess();");
sendLog("✅ Processo finalizado com sucesso!", "db");
?>
</body>
</html>
