<?php
// Inicia sessão
session_start();

// Carrega configurações e conexão com BD ($pdo)
require_once 'config.php'; 

// --- 1. SEGURANÇA ---
if (!isset($_SESSION['user_logged_in']) || $_SESSION['user_logged_in'] !== true) {
    header("Location: login.php");
    exit;
}

if (($_SESSION['user_role'] ?? '') !== 'admin') {
    header("Location: /");
    exit;
}

// DEFINIÇÃO DOS MENUS DO SISTEMA
$sistema_menus = [
    'dashboard'  => ['label' => 'Dashboard',  'icon' => 'bi-speedometer2', 'link' => '/'],
    'rotas'      => ['label' => 'Rotas',      'icon' => 'bi-map',          'link' => '#'],
    'veiculos'   => ['label' => 'Veículos',   'icon' => 'bi-bus-front',    'link' => '#'],
    'motoristas' => ['label' => 'Motoristas', 'icon' => 'bi-person-vcard', 'link' => '#'],
    'escala'     => ['label' => 'Escala',     'icon' => 'bi-calendar-week', 'link' => 'escala.php'],
    'relatorios' => ['label' => 'Relatórios', 'icon' => 'bi-file-earmark-text', 'link' => 'relatorio.php']
];

// [NOVO] LISTA DE EMPRESAS DISPONÍVEIS
// Adicione ou remova empresas aqui conforme necessário
$lista_empresas = [
    'AAM',
    "AD'ORO",
    'B. BOSCH',
    'BOLLHOFF',
    'CMR INDÚSTRIA - LIZ',
    'CPQ',
    'DROGA RAIA',
    'HELLERMANN',
    'JDE COFFE - JACOBS DOUWE EGBER',
    'MERCADO LIVRE GRU I',
    'MERCADO LIVRE RC01',
    'MERCADO LIVRE SP09/15',
    'MERCADO LIVRE SP10',
    'MERCADOLIVRE SP16',
    'NISSEI',
    'OUTLET',
    'PUCC',
    'RED BULL',
    'SILGAN (ALBEA)',
    'STIHL',
    'THEOTO',
    'USP',
    'WEIR'
];

$msg = '';
$msg_tipo = '';

// --- 2. PROCESSAMENTO DE FORMULÁRIOS (POST) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $acao = $_POST['acao'] ?? '';

    // --- A. ADICIONAR USUÁRIO ---
    if ($acao === 'adicionar') {
        $nome = trim($_POST['full_name']);
        $user = trim($_POST['username']);
        $pass = $_POST['password']; 
        $role = $_POST['role'];
        
        // [ALTERADO] Captura empresas via array (checkboxes)
        $comps_selecionadas = $_POST['companies'] ?? []; 
        $json_comps = !empty($comps_selecionadas) ? json_encode(array_values($comps_selecionadas)) : NULL;

        // Captura menus
        $menus_selecionados = $_POST['menus'] ?? []; 
        $json_menus = json_encode($menus_selecionados);

        $hash = password_hash($pass, PASSWORD_DEFAULT);

        try {
            $stmt = $pdo->prepare("INSERT INTO users (username, password, full_name, role, allowed_companies, allowed_menus) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->execute([$user, $hash, $nome, $role, $json_comps, $json_menus]);
            $msg = "Usuário criado com sucesso!";
            $msg_tipo = "success";
        } catch (PDOException $e) {
            $msg = "Erro ao criar: " . $e->getMessage();
            $msg_tipo = "danger";
        }
    }

    // --- B. EDITAR USUÁRIO ---
    elseif ($acao === 'editar') {
        $id = $_POST['user_id'];
        $nome = trim($_POST['full_name']);
        $user = trim($_POST['username']);
        $role = $_POST['role'];
        $nova_senha = $_POST['password'];

        // [ALTERADO] Captura empresas via array (checkboxes)
        $comps_selecionadas = $_POST['companies'] ?? [];
        $json_comps = !empty($comps_selecionadas) ? json_encode(array_values($comps_selecionadas)) : NULL;

        // Captura menus
        $menus_selecionados = $_POST['menus'] ?? [];
        $json_menus = json_encode($menus_selecionados);

        try {
            if (!empty($nova_senha)) {
                $hash = password_hash($nova_senha, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare("UPDATE users SET full_name=?, username=?, role=?, allowed_companies=?, allowed_menus=?, password=? WHERE id=?");
                $stmt->execute([$nome, $user, $role, $json_comps, $json_menus, $hash, $id]);
            } else {
                $stmt = $pdo->prepare("UPDATE users SET full_name=?, username=?, role=?, allowed_companies=?, allowed_menus=? WHERE id=?");
                $stmt->execute([$nome, $user, $role, $json_comps, $json_menus, $id]);
            }
            $msg = "Usuário atualizado com sucesso!";
            $msg_tipo = "success";
        } catch (PDOException $e) {
            $msg = "Erro ao atualizar: " . $e->getMessage();
            $msg_tipo = "danger";
        }
    }

    // --- C. EXCLUIR USUÁRIO ---
    elseif ($acao === 'excluir') {
        $id = $_POST['user_id'];
        if ($id == $_SESSION['user_id']) {
            $msg = "Você não pode excluir a si mesmo!";
            $msg_tipo = "warning";
        } else {
            try {
                $stmt = $pdo->prepare("DELETE FROM users WHERE id = ?");
                $stmt->execute([$id]);
                $msg = "Usuário removido.";
                $msg_tipo = "success";
            } catch (PDOException $e) {
                $msg = "Erro ao excluir: " . $e->getMessage();
                $msg_tipo = "danger";
            }
        }
    }
}

// --- 3. BUSCAR USUÁRIOS ---
$usuarios = $pdo->query("SELECT * FROM users ORDER BY full_name ASC")->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <title>Gestão de Usuários - Viação Mimo</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <style>
    body { background-color: #f8f9fa; font-family: 'Segoe UI', sans-serif; overflow-x: hidden; }
    .sidebar { background-color: #0b1f3a; color: white; min-height: 100vh; width: 250px; position: fixed; z-index: 1000; transition: all 0.3s ease; overflow-y: auto; }
    .sidebar a { color: #d1d5db; display: flex; align-items: center; padding: 14px 20px; text-decoration: none; border-left: 4px solid transparent; font-weight: 500; white-space: nowrap; overflow: hidden; }
    .sidebar a i { min-width: 30px; font-size: 1.1rem; }
    .sidebar a.active, .sidebar a:hover { background-color: #1b2e52; color: white; border-left-color: #0d6efd; }
    .sidebar .logo-container img { transition: all 0.3s; max-width: 160px; }
    .sidebar.toggled { width: 80px; }
    .sidebar.toggled .logo-container img { max-width: 50px; }
    .sidebar.toggled a span { display: none; }
    .sidebar.toggled a { justify-content: center; padding: 14px 0; }
    .sidebar.toggled a i { margin-right: 0 !important; }
    .content { margin-left: 250px; padding: 30px; transition: all 0.3s ease; }
    .content.toggled { margin-left: 80px; }
    /* Estilo extra para a lista de checkbox */
    .checkbox-list { max-height: 150px; overflow-y: auto; }
    </style>
</head>
<body>

<div class="sidebar d-flex flex-column" id="sidebar">
    <div class="text-center py-4 bg-dark bg-opacity-25 logo-container">
        <img src="https://viacaomimo.com.br/wp-content/uploads/2023/07/Background-12-1.png" alt="Logo">
    </div>

    <?php foreach ($sistema_menus as $key => $menu): ?>
        <a href="<?php echo $menu['link']; ?>" title="<?php echo $menu['label']; ?>">
            <i class="bi <?php echo $menu['icon']; ?> me-2"></i>
            <span><?php echo $menu['label']; ?></span>
        </a>
    <?php endforeach; ?>

    <?php if (($_SESSION['user_role'] ?? '') === 'admin'): ?>
        <a href="admin.php" class="active" title="Usuários"><i class="bi bi-people-fill me-2"></i><span>Usuários</span></a>
    <?php endif; ?>
    
    <a href="logout.php" class="mt-auto text-danger border-top border-secondary" title="Sair"><i class="bi bi-box-arrow-right me-2"></i><span>Sair</span></a>
</div>

<div class="content" id="content">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div class="d-flex align-items-center gap-3">
            <button class="btn btn-outline-dark border-0 shadow-sm" id="btnToggleMenu">
                <i class="bi bi-list fs-5"></i>
            </button>
            <h3 class="fw-bold text-dark">Gestão de Usuários</h3>
            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#modalAdicionar">
                <i class="bi bi-person-plus-fill me-2"></i>Novo Usuário
            </button>
        </div>
    </div>

    <?php if($msg): ?>
        <div class="alert alert-<?php echo $msg_tipo; ?> alert-dismissible fade show" role="alert">
            <?php echo $msg; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <div class="card border-0 shadow-sm">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>ID</th>
                            <th>Nome Completo</th>
                            <th>Usuário</th>
                            <th>Nível</th>
                            <th>Acessos (Menus)</th>
                            <th>Empresas</th>
                            <th class="text-end">Ações</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($usuarios as $u): 
                            $menus_db = json_decode($u['allowed_menus'] ?? '[]', true);
                            if(!is_array($menus_db)) $menus_db = [];
                            $menus_display = implode(", ", array_map('ucfirst', $menus_db));
                            if(empty($menus_display)) $menus_display = '<span class="text-muted small">Nenhum</span>';
                            if($u['role'] === 'admin') $menus_display = '<span class="badge bg-success">Todos</span>';

                            // Tratamento Empresas
                            $comps_db = json_decode($u['allowed_companies'] ?? '[]', true);
                            if(!is_array($comps_db)) $comps_db = [];
                            $comps_display = implode(", ", $comps_db);
                            if(empty($comps_display)) $comps_display = '<span class="text-muted small">-</span>';
                            
                            // JSON para atributos do botão (passa o array JSON direto, não string separada por virgula)
                            $json_menus_attr = htmlspecialchars($u['allowed_menus'] ?? '[]');
                            $json_comps_attr = htmlspecialchars($u['allowed_companies'] ?? '[]');
                        ?>
                        <tr>
                            <td><?php echo $u['id']; ?></td>
                            <td class="fw-bold"><?php echo htmlspecialchars($u['full_name']); ?></td>
                            <td><?php echo htmlspecialchars($u['username']); ?></td>
                            <td>
                                <span class="badge <?php echo $u['role'] === 'admin' ? 'bg-danger' : 'bg-info'; ?>">
                                    <?php echo strtoupper($u['role']); ?>
                                </span>
                            </td>
                            <td class="small"><?php echo $menus_display; ?></td>
                            <td class="small"><?php echo $comps_display; ?></td>
                            <td class="text-end">
                                <button class="btn btn-sm btn-outline-primary me-1 btn-editar" 
                                    data-id="<?php echo $u['id']; ?>"
                                    data-nome="<?php echo htmlspecialchars($u['full_name']); ?>"
                                    data-user="<?php echo htmlspecialchars($u['username']); ?>"
                                    data-role="<?php echo $u['role']; ?>"
                                    data-comps='<?php echo $json_comps_attr; ?>'
                                    data-menus='<?php echo $json_menus_attr; ?>' 
                                    data-bs-toggle="modal" data-bs-target="#modalEditar">
                                    <i class="bi bi-pencil-square"></i>
                                </button>
                                <form action="admin.php" method="POST" class="d-inline" onsubmit="return confirm('Tem certeza?');">
                                    <input type="hidden" name="acao" value="excluir">
                                    <input type="hidden" name="user_id" value="<?php echo $u['id']; ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
                                </form>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="modalAdicionar" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <form class="modal-content" method="POST" action="admin.php">
      <div class="modal-header">
        <h5 class="modal-title">Novo Usuário</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <input type="hidden" name="acao" value="adicionar">
        
        <div class="mb-3">
            <label class="form-label">Nome Completo</label>
            <input type="text" class="form-control" name="full_name" required>
        </div>
        <div class="mb-3">
            <label class="form-label">Usuário</label>
            <input type="text" class="form-control" name="username" required>
        </div>
        <div class="mb-3">
            <label class="form-label">Senha</label>
            <input type="password" class="form-control" name="password" required>
        </div>
        <div class="mb-3">
            <label class="form-label">Nível de Acesso</label>
            <select class="form-select" name="role" onchange="togglePermissions(this, 'add_menus_container')">
                <option value="user">Usuário Comum</option>
                <option value="admin">Administrador (Acesso Total)</option>
            </select>
        </div>

        <div class="mb-3">
            <label class="form-label fw-bold">Empresas Permitidas:</label>
            <div class="card p-2 bg-light checkbox-list">
                <?php foreach($lista_empresas as $empresa): ?>
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" name="companies[]" value="<?php echo $empresa; ?>" id="comp_add_<?php echo md5($empresa); ?>">
                    <label class="form-check-label" for="comp_add_<?php echo md5($empresa); ?>">
                        <?php echo $empresa; ?>
                    </label>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="mb-3" id="add_menus_container">
            <label class="form-label fw-bold">Menus Permitidos:</label>
            <div class="card p-2 bg-light">
                <?php foreach($sistema_menus as $key => $menu): ?>
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" name="menus[]" value="<?php echo $key; ?>" id="menu_add_<?php echo $key; ?>">
                    <label class="form-check-label" for="menu_add_<?php echo $key; ?>">
                        <i class="bi <?php echo $menu['icon']; ?>"></i> <?php echo $menu['label']; ?>
                    </label>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
        <button type="submit" class="btn btn-primary">Salvar</button>
      </div>
    </form>
  </div>
</div>

<div class="modal fade" id="modalEditar" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <form class="modal-content" method="POST" action="admin.php">
      <div class="modal-header">
        <h5 class="modal-title">Editar Usuário</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <input type="hidden" name="acao" value="editar">
        <input type="hidden" name="user_id" id="edit_id">
        
        <div class="mb-3">
            <label class="form-label">Nome Completo</label>
            <input type="text" class="form-control" name="full_name" id="edit_name" required>
        </div>
        <div class="mb-3">
            <label class="form-label">Usuário</label>
            <input type="text" class="form-control" name="username" id="edit_user" required>
        </div>
        <div class="mb-3">
            <label class="form-label">Nova Senha</label>
            <input type="password" class="form-control" name="password" placeholder="Deixe em branco para manter">
        </div>
        <div class="mb-3">
            <label class="form-label">Nível de Acesso</label>
            <select class="form-select" name="role" id="edit_role" onchange="togglePermissions(this, 'edit_menus_container')">
                <option value="user">Usuário Comum</option>
                <option value="admin">Administrador</option>
            </select>
        </div>

        <div class="mb-3">
            <label class="form-label fw-bold">Empresas Permitidas:</label>
            <div class="card p-2 bg-light checkbox-list">
                <?php foreach($lista_empresas as $empresa): ?>
                <div class="form-check">
                    <input class="form-check-input check-comp-edit" type="checkbox" name="companies[]" value="<?php echo $empresa; ?>" id="comp_edit_<?php echo md5($empresa); ?>">
                    <label class="form-check-label" for="comp_edit_<?php echo md5($empresa); ?>">
                        <?php echo $empresa; ?>
                    </label>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="mb-3" id="edit_menus_container">
            <label class="form-label fw-bold">Menus Permitidos:</label>
            <div class="card p-2 bg-light">
                <?php foreach($sistema_menus as $key => $menu): ?>
                <div class="form-check">
                    <input class="form-check-input check-menu-edit" type="checkbox" name="menus[]" value="<?php echo $key; ?>" id="menu_edit_<?php echo $key; ?>">
                    <label class="form-check-label" for="menu_edit_<?php echo $key; ?>">
                        <i class="bi <?php echo $menu['icon']; ?>"></i> <?php echo $menu['label']; ?>
                    </label>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
        <button type="submit" class="btn btn-primary">Salvar Alterações</button>
      </div>
    </form>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
function togglePermissions(selectInfo, containerId) {
    const container = document.getElementById(containerId);
    if(selectInfo.value === 'admin') {
        container.style.opacity = '0.5';
        container.style.pointerEvents = 'none'; 
    } else {
        container.style.opacity = '1';
        container.style.pointerEvents = 'auto';
    }
}

// Lógica de preenchimento do Editar
const editBtns = document.querySelectorAll('.btn-editar');
const editId = document.getElementById('edit_id');
const editName = document.getElementById('edit_name');
const editUser = document.getElementById('edit_user');
const editRole = document.getElementById('edit_role');

editBtns.forEach(btn => {
    btn.addEventListener('click', function() {
        // Campos Simples
        editId.value = this.getAttribute('data-id');
        editName.value = this.getAttribute('data-nome');
        editUser.value = this.getAttribute('data-user');
        editRole.value = this.getAttribute('data-role');
        
        // Efeito visual do admin
        togglePermissions(editRole, 'edit_menus_container');

        // --- PREENCHER MENUS ---
        document.querySelectorAll('.check-menu-edit').forEach(ck => ck.checked = false);
        const rawMenus = this.getAttribute('data-menus');
        try {
            const menusArray = JSON.parse(rawMenus || '[]');
            menusArray.forEach(menuKey => {
                const el = document.getElementById('menu_edit_' + menuKey);
                if(el) el.checked = true;
            });
        } catch(e) { console.error("Erro menus", e); }

        // --- [NOVO] PREENCHER EMPRESAS ---
        // 1. Desmarcar todas primeiro
        document.querySelectorAll('.check-comp-edit').forEach(ck => ck.checked = false);
        // 2. Pegar JSON do botão
        const rawComps = this.getAttribute('data-comps');
        // 3. Marcar as que o usuário tem
        try {
            const compsArray = JSON.parse(rawComps || '[]');
            // Como as empresas são strings (ex: "RED BULL") e usamos MD5 no ID para evitar espaços...
            // Vamos iterar sobre os checkboxes e ver se o valor deles está no array do usuário.
            document.querySelectorAll('.check-comp-edit').forEach(ck => {
                if(compsArray.includes(ck.value)) {
                    ck.checked = true;
                }
            });
        } catch(e) { console.error("Erro empresas", e); }
    });
});

const btnToggle = document.getElementById('btnToggleMenu');
const sidebar = document.getElementById('sidebar');
const content = document.getElementById('content');

if (btnToggle) {
    btnToggle.addEventListener('click', function() {
        sidebar.classList.toggle('toggled');
        content.classList.toggle('toggled');
    });
}
</script>
</body>
</html>
