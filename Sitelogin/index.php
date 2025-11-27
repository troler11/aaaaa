<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <title>Telemetria > Supabase (V8 API Token)</title>
    <style>
        body { font-family: monospace; background: #121212; color: #ccc; padding: 20px; }
        .log { border-bottom: 1px solid #333; padding: 4px 0; }
        .err { color: #ff5555; font-weight: bold; }
        .suc { color: #50fa7b; font-weight: bold; }
        .inf { color: #8be9fd; }
        .token { color: #ffb86c; border: 1px dashed #555; padding: 2px; }
    </style>
</head>
<body>
<h3>Processamento de Telemetria (V8 - Endpoint Oficial)</h3>
<div id="logs"></div>

<script>
    function log(msg, type='') {
        const d = document.createElement('div');
        d.className = 'log ' + type;
        d.innerText = '[' + new Date().toLocaleTimeString() + '] ' + msg;
        const box = document.getElementById('logs');
        box.appendChild(d);
        window.scrollTo(0, document.body.scrollHeight);
    }
</script>

<?php
// --- CONFIGURAÇÕES DO SERVIDOR ---
@apache_setenv('no-gzip', 1); 
@ini_set('zlib.output_compression', 0);
@ini_set('implicit_flush', 1); 
for ($i = 0; $i < ob_get_level(); $i++) { ob_end_flush(); }
ob_implicit_flush(1);
set_time_limit(0); 
error_reporting(E_ALL); 
date_default_timezone_set('America/Sao_Paulo');

function jsLog($msg, $type='') {
    $c = addslashes($msg);
    echo "<script>log('$c', '$type');</script>";
    echo str_pad('', 4096)."\n"; 
    flush(); 
}

// ==========================================================
// 1. CONFIGURAÇÕES
// ==========================================================

// -> SUPABASE
$SB_HOST = "aws-1-us-east-2.pooler.supabase.com"; // Seu Host do Supabase
$SB_DB   = "postgres";
$SB_USER = "postgres.iztzyvygulxlavixngeo";
$SB_PASS = "Lukinha2009@"; // Senha do banco (não é a chave da API)
$SB_PORT = "6543";

// -> FULLTRACK / ABM
$ABM_USER = "lucas";
$ABM_PASS = "Lukinha2009";
$URL_BASE = "https://abmtecnologia.abmprotege.net";

// Arquivo Cookie
if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
    $COOKIE_FILE = sys_get_temp_dir() . '\abm_cookie_v8.txt';
} else {
    $COOKIE_FILE = '/tmp/abm_cookie_v8.txt';
}

// Tokens LocationIQ
$GEO_TOKENS = [
    "pk.5dec6e778adac747992ee2564e7a57e1", "pk.74da1c80d1fdd103198bb2729dfc24b9"
];

// ==========================================================
// 2. FUNÇÕES DE REDE
// ==========================================================

function curl_req($method, $url, $cookie, $data=null, $headers=[]) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
    curl_setopt($ch, CURLOPT_COOKIEJAR, $cookie);
    curl_setopt($ch, CURLOPT_COOKIEFILE, $cookie);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30); 
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    
    $defHeaders = [
        "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36",
        "Accept: application/json, text/javascript, */*; q=0.01"
    ];
    curl_setopt($ch, CURLOPT_HTTPHEADER, array_merge($defHeaders, $headers));

    if ($method == 'POST') {
        curl_setopt($ch, CURLOPT_POST, 1);
        if ($data !== null) {
            // Se for array, form-urlencoded, se for string vazia, manda vazia
            curl_setopt($ch, CURLOPT_POSTFIELDS, is_array($data) ? http_build_query($data) : $data);
        }
    }
    
    $res = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    
    return ['body' => $res, 'code' => $code, 'err' => $err];
}

/**
 * 1. FAZ LOGIN (Gera o Cookie)
 */
function realizarLogin() {
    global $ABM_USER, $ABM_PASS, $COOKIE_FILE, $URL_BASE;
    if (file_exists($COOKIE_FILE)) unlink($COOKIE_FILE);
    
    jsLog("🔑 Realizando Login...", "inf");
    
    $resp = curl_req('POST', "$URL_BASE/emp/abmtecnologia", $COOKIE_FILE, 
        ["login"=>$ABM_USER, "senha"=>$ABM_PASS, "password"=>$ABM_PASS], 
        ["Content-Type: application/x-www-form-urlencoded", "Origin: $URL_BASE", "Referer: $URL_BASE/emp/abmtecnologia"]
    );

    if ($resp['code'] != 200) {
        jsLog("❌ Login Falhou: HTTP " . $resp['code'], "err");
        return false;
    }
    return true;
}

/**
 * 2. CHAMA API OFICIAL DE TOKEN
 */
function obterTokenOficial() {
    global $COOKIE_FILE, $URL_BASE;
    
    jsLog("🔄 Solicitando Token na API /token/Api_ftk4...", "inf");

    // Headers exatos do seu cURL
    $headersToken = [
        "Content-Length: 0", // Importante
        "Content-Type: application/x-www-form-urlencoded",
        "Origin: $URL_BASE",
        "Referer: $URL_BASE/dashboard_controller",
        "X-Requested-With: XMLHttpRequest"
    ];

    // POST Vazio, mas com os cookies da sessão
    $resp = curl_req('POST', "$URL_BASE/token/Api_ftk4", $COOKIE_FILE, "", $headersToken);

    if ($resp['code'] != 200) {
        jsLog("❌ Erro ao pedir token: HTTP " . $resp['code'], "err");
        return false;
    }

    $json = json_decode($resp['body'], true);
    
    // O sistema geralmente retorna { "access_token": "..." } ou similar
    if (isset($json['access_token'])) {
        $tk = $json['access_token'];
        jsLog("🎯 Token Obtido: " . substr($tk, 0, 10) . "...", "token");
        return $tk;
    } 
    // Caso retorne direto a string ou outra chave
    elseif (isset($json['token'])) {
        return $json['token'];
    }
    
    jsLog("⚠️ Resposta do Token inesperada: " . substr($resp['body'], 0, 100), "err");
    return false;
}

// ==========================================================
// 3. FLUXO PRINCIPAL
// ==========================================================

// Conexão DB
try {
    $pdo = new PDO("pgsql:host=$SB_HOST;port=$SB_PORT;dbname=$SB_DB;", $SB_USER, $SB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    jsLog("✅ DB Conectado.", "suc");
} catch (PDOException $e) { jsLog("❌ Erro DB: ".$e->getMessage(), "err"); }

$dt = new DateTime('yesterday');
$dtStr = $dt->format('d/m/Y');
jsLog("📅 Data: $dtStr", "inf");

// PASSO 1: LOGIN
if (!realizarLogin()) exit;

// PASSO 2: PEGAR TOKEN PELA API OFICIAL
$bearerToken = obterTokenOficial();

if (!$bearerToken) {
    jsLog("⛔ Não foi possível gerar o token. Abortando.", "err");
    exit;
}

// PASSO 3: RELATÓRIO
$payload = [
    'id_cliente'=>'195577', 'id_motorista'=>'0', 'dt_inicial'=>"$dtStr 00:00:00", 'dt_final'=>"$dtStr 23:59:59",
    'id_indice'=>'7259', 'id_usuario'=>'250095', 'visualizar_por'=>'motorista'
];

$headersFT = ["Authorization: Bearer $bearerToken"];

jsLog("📡 Baixando Relatório...", "inf");
$resp = curl_req('POST', "https://api-fulltrack4.fulltrackapp.com/relatorio/DriverBehavior/gerar/", $COOKIE_FILE, $payload, $headersFT);

if ($resp['code'] != 200) {
    jsLog("❌ Erro API Relatório ($resp[code]).", "err");
    jsLog("Body: " . substr($resp['body'], 0, 200), "err");
    exit;
}

$json = json_decode($resp['body'], true);
if (!$json) { jsLog("❌ JSON Inválido.", "err"); exit; }

// PASSO 4: FLATTEN
$items = [];
foreach ($json as $i) {
    if (!isset($i['sub_table'])) continue;
    foreach ($i['sub_table'] as $sub) {
        if (!isset($sub['sub_table_infracao'])) continue;
        foreach ($sub['sub_table_infracao'] as $t) {
            if (in_array($t['tipo_infracao'], ["Motor Ocioso", "Banguela"])) continue;
            foreach ($t['infracoes'] ?? [] as $d) {
                $items[] = [
                    'ativo' => $i['descricao_ativo'], 'placa' => $i['tag_ativo'], 'mot' => $sub['descricao_motorista'],
                    't_inf' => $i['total_infracoes'], 't_pen' => $i['total_penalidade'], 'tipo' => $t['tipo_infracao'],
                    'id_ext' => $d['id_infracao'], 'data' => $d['data'], 'lat' => floatval($d['endereco']['lat']), 'lon' => floatval($d['endereco']['lon']),
                    'vel' => $d['velocidade'], 'pen' => $d['penalidade']
                ];
            }
        }
    }
}

$total = count($items);
jsLog("📋 Total: $total infrações.", "inf");
if ($total == 0) { jsLog("🎉 Sem dados.", "suc"); exit; }

// PASSO 5: PROCESSAMENTO
$chunks = array_chunk($items, 4);
$processed = 0;

foreach ($chunks as $batch) {
    $mh = curl_multi_init();
    $handles = [];
    
    // Verifica Cache
    foreach ($batch as $k => $row) {
        $found = false;
        if (isset($pdo)) {
            try {
                $stmt = $pdo->prepare("SELECT endereco_completo FROM cache_enderecos WHERE latitude = :lat AND longitude = :lon");
                $stmt->execute([':lat' => $row['lat'], ':lon' => $row['lon']]);
                $cached = $stmt->fetchColumn();
                if ($cached) { $batch[$k]['end'] = $cached; $batch[$k]['src'] = 'cache'; $found = true; }
            } catch (Exception $e) {}
        }
        
        if (!$found) {
            if ($row['lat'] == 0) { $batch[$k]['end'] = 'N/A'; $batch[$k]['src'] = 'inv'; }
            else {
                $batch[$k]['src'] = 'api';
                $tk = $GEO_TOKENS[$k % count($GEO_TOKENS)];
                $url = "https://us1.locationiq.com/v1/reverse?key=$tk&lat={$row['lat']}&lon={$row['lon']}&format=json";
                $ch = curl_init($url);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
                curl_setopt($ch, CURLOPT_TIMEOUT, 5);
                curl_multi_add_handle($mh, $ch);
                $handles[$k] = $ch;
            }
        }
    }
    
    $running = null; do { curl_multi_exec($mh, $running); } while ($running);

    // Salva
    foreach ($batch as $k => $row) {
        if (isset($handles[$k])) {
            $ch = $handles[$k];
            if (curl_getinfo($ch, CURLINFO_HTTP_CODE) == 200) {
                $geo = json_decode(curl_multi_getcontent($ch), true);
                $addr = ($geo['address']['road'] ?? '') . ", " . ($geo['address']['city'] ?? '');
                $batch[$k]['end'] = trim($addr, ", ");
                if (isset($pdo)) {
                    $pdo->prepare("INSERT INTO cache_enderecos (latitude, longitude, endereco_completo) VALUES (:l, :ln, :e) ON CONFLICT DO NOTHING")->execute([':l'=>$row['lat'], ':ln'=>$row['lon'], ':e'=>$batch[$k]['end']]);
                }
            } else { $batch[$k]['end'] = "Erro API Geo"; }
            curl_multi_remove_handle($mh, $ch); curl_close($ch);
        }

        if (isset($pdo)) {
            try {
                $dtObj = DateTime::createFromFormat('d/m/Y H:i:s', $row['data']);
                $sql = "INSERT INTO relatorio_infracoes 
                    (codigo_ativo, placa, motorista, total_infracoes_geral, total_penalidades_geral, tipo_infracao, id_infracao_externo, data_infracao, velocidade, penalidade_valor, latitude, longitude, endereco)
                    VALUES (:ativo, :placa, :mot, :ti, :tp, :tip, :id, :dt, :vel, :pen, :lat, :lon, :end)
                    ON CONFLICT (id_infracao_externo) DO UPDATE SET endereco = EXCLUDED.endereco";
                $pdo->prepare($sql)->execute([
                    ':ativo'=>$row['ativo'], ':placa'=>$row['placa'], ':mot'=>$row['mot'], ':ti'=>$row['t_inf'], ':tp'=>$row['t_pen'],
                    ':tip'=>$row['tipo'], ':id'=>$row['id_ext'], ':dt'=>$dtObj ? $dtObj->format('Y-m-d H:i:s') : null,
                    ':vel'=>(int)$row['vel'], ':pen'=>$row['pen'], ':lat'=>$row['lat'], ':lon'=>$row['lon'], ':end'=>$batch[$k]['end']
                ]);
            } catch (Exception $e) { jsLog("Erro Insert: ".$e->getMessage(), "err"); }
        }
        $processed++;
    }
    if ($handles) curl_multi_close($mh);
    jsLog("✅ Processado: $processed / $total", "inf");
}

jsLog("🎉 Finalizado!", "suc");
?>
</body>
</html>
