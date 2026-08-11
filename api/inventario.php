<?php
// api/inventario.php — AJAX endpoint para o módulo de inventário
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

// Auth: Bearer token (PWA) ou sessão PHP (web)
$cur_uid  = 0;
$cur_user = 'desconhecido';
$authed   = false;

// Bearer token auth (same pattern as todos.php)
$hdrs   = getallheaders();
$bearer = '';
if (isset($hdrs['Authorization'])) {
    if (preg_match('/Bearer\s(\S+)/', $hdrs['Authorization'], $bm)) $bearer = $bm[1];
} elseif (isset($hdrs['authorization'])) {
    if (preg_match('/Bearer\s(\S+)/', $hdrs['authorization'], $bm)) $bearer = $bm[1];
}
if ($bearer) {
    include_once __DIR__ . '/../config.php';
    $tmpDb = new mysqli($db_host, $db_user, $db_pass, $db_name);
    if (!$tmpDb->connect_error) {
        $tmpDb->set_charset('utf8mb4');
        $st = $tmpDb->prepare('SELECT user_id, username FROM user_tokens WHERE token = ?');
        $st->bind_param('s', $bearer);
        $st->execute();
        $row = $st->get_result()->fetch_assoc();
        $st->close(); $tmpDb->close();
        if ($row) { $cur_uid = (int)$row['user_id']; $cur_user = $row['username']; $authed = true; }
    }
} else {
    // Session auth
    session_start();
    if (isset($_SESSION['username'])) {
        $cur_uid  = (int)($_SESSION['user_id'] ?? 0);
        $cur_user = $_SESSION['username'];
        $authed   = true;
    }
}
if (!$authed) { http_response_code(401); echo json_encode(['error'=>'Não autenticado']); exit; }

include_once __DIR__ . '/../config.php';

try {
    $pdo = new PDO("mysql:host=$db_host;dbname=$db_name;charset=utf8mb4", $db_user, $db_pass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
} catch (Exception $e) {
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Erro de ligação BD']); exit;
}

$action = $_GET['action'] ?? '';

// Carregar armários dinâmicos
try {
    $ARMARIOS = $pdo->query("SELECT nome FROM inventario_armarios ORDER BY ordem, nome")->fetchAll(PDO::FETCH_COLUMN);
} catch (Exception $e) {
    $ARMARIOS = ['A1','A2','A3','A4','A5','A6','A7','A8'];
}

// ── export (download — sem JSON header) ───────────────────────
if ($action === 'export') {
    $arm  = $_GET['armario'] ?? '';
    $arms = ($arm && in_array($arm, $ARMARIOS, true)) ? [$arm] : $ARMARIOS;
    $sheets = [];
    foreach ($arms as $a) {
        $rows = $pdo->query("SELECT descricao,quantidade,prateleira,caixa,projeto,link,notas,last_edited FROM inventario_items WHERE armario='".addslashes($a)."' ORDER BY descricao")->fetchAll(PDO::FETCH_ASSOC);
        $data = [['Descrição','Quantidade','Prateleira','Caixa','Projeto','Link','Notas','Última edição']];
        foreach ($rows as $r) $data[] = array_values($r);
        $sheets[$a] = $data;
    }
    // ── XLSX helpers ────────────────────────────────────────────
    function api_col_letter(int $col): string {
        $s = '';
        for ($c = $col; $c >= 0; $c = intval($c / 26) - 1)
            $s = chr(65 + ($c % 26)) . $s;
        return $s;
    }
    function api_stream_xlsx(array $sheets, string $filename): void {
        $strings = [];
        $strIdx = function(string $s) use (&$strings): int {
            $k = array_search($s, $strings, true);
            if ($k === false) { $strings[] = $s; return count($strings) - 1; }
            return $k;
        };
        $names = array_keys($sheets); $sheetXmls = [];
        foreach ($names as $si => $sname) {
            $x = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
               . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>';
            foreach ($sheets[$sname] as $ri => $row) {
                $x .= '<row r="'.($ri+1).'">';
                foreach ($row as $ci => $val) {
                    $ref = api_col_letter($ci).($ri+1);
                    if ($val === null || $val === '') { $x .= '<c r="'.$ref.'"/>'; continue; }
                    if (is_numeric($val) && !preg_match('/^0\d/', (string)$val))
                        $x .= '<c r="'.$ref.'"><v>'.htmlspecialchars((string)$val,ENT_XML1).'</v></c>';
                    else
                        $x .= '<c r="'.$ref.'" t="s"><v>'.$strIdx((string)$val).'</v></c>';
                }
                $x .= '</row>';
            }
            $x .= '</sheetData></worksheet>';
            $sheetXmls[] = $x;
        }
        $n = count($names);
        $ss = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" count="'.count($strings).'" uniqueCount="'.count($strings).'">';
        foreach ($strings as $s) $ss .= '<si><t>'.htmlspecialchars($s,ENT_XML1).'</t></si>';
        $ss .= '</sst>';
        $ct = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/sharedStrings.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sharedStrings+xml"/>';
        for ($i=0;$i<$n;$i++) $ct .= '<Override PartName="/xl/worksheets/sheet'.($i+1).'.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
        $ct .= '</Types>';
        $rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
              . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
              . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
              . '</Relationships>';
        $wb = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets>';
        foreach ($names as $si => $sname)
            $wb .= '<sheet name="'.htmlspecialchars($sname,ENT_XML1).'" sheetId="'.($si+1).'" r:id="rId'.($si+2).'"/>';
        $wb .= '</sheets></workbook>';
        $wbr = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
             . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
             . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/sharedStrings" Target="sharedStrings.xml"/>';
        for ($i=0;$i<$n;$i++)
            $wbr .= '<Relationship Id="rId'.($i+2).'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet'.($i+1).'.xml"/>';
        $wbr .= '</Relationships>';
        $tmp = tempnam(sys_get_temp_dir(), 'inv_');
        $zip = new ZipArchive();
        $zip->open($tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('[Content_Types].xml', $ct);
        $zip->addFromString('_rels/.rels', $rels);
        $zip->addFromString('xl/workbook.xml', $wb);
        $zip->addFromString('xl/_rels/workbook.xml.rels', $wbr);
        $zip->addFromString('xl/sharedStrings.xml', $ss);
        for ($i=0;$i<$n;$i++) $zip->addFromString('xl/worksheets/sheet'.($i+1).'.xml', $sheetXmls[$i]);
        $zip->close();
        while (ob_get_level()) ob_end_clean();
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="'.$filename.'"');
        header('Content-Length: '.filesize($tmp));
        readfile($tmp);
        unlink($tmp);
        exit;
    }
    api_stream_xlsx($sheets, 'Inventario_' . date('Ymd') . '.xlsx');
    exit;
}

header('Content-Type: application/json');

// ── list ──────────────────────────────────────────────────────
if ($action === 'list') {
    $arm = $_GET['armario'] ?? '';
    $q   = trim($_GET['q'] ?? '');
    $sql = 'SELECT * FROM inventario_items WHERE 1=1';
    $p   = [];
    if ($arm && in_array($arm, $ARMARIOS, true)) { $sql .= ' AND armario=?'; $p[] = $arm; }
    if ($q) {
        $sql .= ' AND (descricao LIKE ? OR projeto LIKE ? OR caixa LIKE ? OR prateleira LIKE ? OR notas LIKE ?)';
        $lq = "%$q%";
        $p  = array_merge($p, [$lq,$lq,$lq,$lq,$lq]);
    }
    $sql .= ' ORDER BY armario, descricao';
    $st = $pdo->prepare($sql); $st->execute($p);
    echo json_encode($st->fetchAll(PDO::FETCH_ASSOC));
    exit;
}

// ── save ──────────────────────────────────────────────────────
if ($action === 'save' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $d   = json_decode(file_get_contents('php://input'), true) ?? [];
    $id  = (int)($d['id'] ?? 0);
    $arm = $d['armario'] ?? '';
    if (!in_array($arm, $ARMARIOS, true)) { echo json_encode(['error'=>'Armário inválido']); exit; }
    $desc  = trim($d['descricao'] ?? '');
    if (!$desc) { echo json_encode(['error'=>'Descrição obrigatória']); exit; }
    $qty   = trim($d['quantidade']  ?? '');
    $prat  = trim($d['prateleira']  ?? '');
    $caixa = trim($d['caixa']       ?? '');
    $proj  = trim($d['projeto']     ?? '');
    $link  = trim($d['link']        ?? '');
    $notas = trim($d['notas']       ?? '');
    $led   = ($d['last_edited'] ?? '') ?: null;

    if ($id) {
        $pdo->prepare('UPDATE inventario_items SET armario=?,descricao=?,quantidade=?,prateleira=?,caixa=?,projeto=?,link=?,notas=?,last_edited=?,updated_at=NOW() WHERE id=?')
            ->execute([$arm,$desc,$qty,$prat,$caixa,$proj,$link,$notas,$led,$id]);
    } else {
        $pdo->prepare('INSERT INTO inventario_items (armario,descricao,quantidade,prateleira,caixa,projeto,link,notas,last_edited,created_by) VALUES (?,?,?,?,?,?,?,?,?,?)')
            ->execute([$arm,$desc,$qty,$prat,$caixa,$proj,$link,$notas,$led,$cur_uid]);
        $id = $pdo->lastInsertId();
    }
    $item = $pdo->query("SELECT * FROM inventario_items WHERE id=$id")->fetch(PDO::FETCH_ASSOC);
    echo json_encode(['ok'=>true,'item'=>$item]);
    exit;
}

// ── delete ────────────────────────────────────────────────────
if ($action === 'delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $d  = json_decode(file_get_contents('php://input'), true) ?? [];
    $id = (int)($d['id'] ?? 0);
    if ($id) $pdo->prepare('DELETE FROM inventario_items WHERE id=?')->execute([$id]);
    echo json_encode(['ok'=>true]);
    exit;
}


// ── import ────────────────────────────────────────────────────
if ($action === 'import' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    set_time_limit(120);
    ini_set('memory_limit', '256M');

    if (empty($_FILES['file']['tmp_name'])) { echo json_encode(['error'=>'Nenhum ficheiro recebido']); exit; }

    function inv_read_ss(string $raw): array {
        $strings = []; $xr = new XMLReader(); $xr->XML($raw); unset($raw);
        $cur = ''; $inT = false;
        while ($xr->read()) {
            if ($xr->nodeType === XMLReader::ELEMENT) {
                if ($xr->localName === 'si') $cur = '';
                elseif ($xr->localName === 't') $inT = true;
            } elseif ($xr->nodeType === XMLReader::TEXT && $inT) { $cur .= $xr->value; }
            elseif ($xr->nodeType === XMLReader::END_ELEMENT) {
                if ($xr->localName === 'si') { $strings[] = $cur; $cur = ''; }
                elseif ($xr->localName === 't') $inT = false;
            }
        }
        $xr->close(); return $strings;
    }

    function inv_read_ws(string $raw, array $strings): array {
        $rows=[]; $cells=[]; $rn=0; $cc=0; $ct=''; $cv=''; $iv=false;
        $xr = new XMLReader(); $xr->XML($raw); unset($raw);
        while ($xr->read()) {
            if ($xr->nodeType === XMLReader::ELEMENT) {
                $ln = $xr->localName;
                if ($ln==='row') { $cells=[]; $rn++; }
                elseif ($ln==='c') {
                    $ref=$xr->getAttribute('r')??''; preg_match('/^([A-Z]+)/',$ref,$cm);
                    $col=$cm[1]??'A'; $cc=0;
                    for ($i=0;$i<strlen($col);$i++) $cc=$cc*26+(ord($col[$i])-64);
                    $cc--; $ct=$xr->getAttribute('t')??''; $cv=''; $iv=false;
                } elseif ($ln==='v') { $iv=true; $cv=''; }
            } elseif (($xr->nodeType===XMLReader::TEXT||$xr->nodeType===XMLReader::CDATA) && $iv) {
                $cv.=$xr->value;
            } elseif ($xr->nodeType===XMLReader::END_ELEMENT) {
                $ln=$xr->localName;
                if ($ln==='v') { $iv=false; $v=$cv; if($ct==='s')$v=$strings[(int)$v]??''; $cells[$cc]=$v; }
                elseif ($ln==='row') { if($rn>1) $rows[]=$cells; }
            }
        }
        $xr->close(); return $rows;
    }

    $zip = new ZipArchive();
    if ($zip->open($_FILES['file']['tmp_name']) !== true) { echo json_encode(['error'=>'Não foi possível abrir o ficheiro']); exit; }

    $ssRaw = $zip->getFromName('xl/sharedStrings.xml') ?: '';
    $strings = $ssRaw ? inv_read_ss($ssRaw) : [];
    unset($ssRaw);

    $wbRaw  = $zip->getFromName('xl/workbook.xml') ?? '';
    $wbRRaw = $zip->getFromName('xl/_rels/workbook.xml.rels') ?? '';
    $rId=[];
    preg_match_all('/Id="([^"]+)"[^>]+Target="([^"]+)"/', $wbRRaw, $rm, PREG_SET_ORDER);
    foreach ($rm as $m) $rId[$m[1]] = $m[2];
    $sf=[];
    preg_match_all('/<sheet\b[^>]+>/i', $wbRaw, $sm);
    foreach ($sm[0] as $tag) {
        if (!preg_match('/name="([^"]+)"/',$tag,$nm)) continue;
        if (!preg_match('/r:id="([^"]+)"/',$tag,$ri)) continue;
        $f=$rId[$ri[1]]??'';
        if ($f) $sf[$nm[1]]=(strpos($f,'/')===0)?ltrim($f,'/') : 'xl/'.$f;
    }

    $existing=[];
    foreach ($pdo->query("SELECT id,armario,descricao FROM inventario_items")->fetchAll(PDO::FETCH_ASSOC) as $r)
        $existing[$r['armario'].'||'.$r['descricao']]=(int)$r['id'];

    $stUpd = $pdo->prepare('UPDATE inventario_items SET quantidade=?,prateleira=?,caixa=?,projeto=?,last_edited=?,updated_at=NOW() WHERE id=?');
    $stIns = $pdo->prepare('INSERT INTO inventario_items (armario,descricao,quantidade,prateleira,caixa,projeto,last_edited,created_by) VALUES (?,?,?,?,?,?,?,?)');
    $imported=$updated=$skipped=0; $errors=[];

    $pdo->beginTransaction();
    try {
        foreach ($ARMARIOS as $arm) {
            if (!isset($sf[$arm])) { $errors[]="Folha '$arm' não encontrada"; continue; }
            $wsRaw=$zip->getFromName($sf[$arm]);
            if (!$wsRaw) { $errors[]="Erro ao ler '$arm'"; continue; }
            $rows=inv_read_ws($wsRaw,$strings); unset($wsRaw);
            foreach ($rows as $cells) {
                $desc=trim($cells[1]??''); if(!$desc){$skipped++;continue;}
                $qty=trim($cells[2]??''); $prat=trim($cells[3]??'');
                $cx=trim($cells[4]??''); $proj=trim($cells[5]??'');
                $led=null; $lr=$cells[6]??'';
                if ($lr!==''&&is_numeric($lr)&&(float)$lr>40000)
                    $led=date('Y-m-d',(int)(((float)$lr-25569)*86400));
                $key=$arm.'||'.$desc;
                if (isset($existing[$key])) { $stUpd->execute([$qty,$prat,$cx,$proj,$led,$existing[$key]]); $updated++; }
                else { $stIns->execute([$arm,$desc,$qty,$prat,$cx,$proj,$led,$cur_uid]); $existing[$key]=(int)$pdo->lastInsertId(); $imported++; }
            }
        }
        $pdo->commit();
    } catch (Exception $e) {
        $pdo->rollBack();
        echo json_encode(['error'=>'Erro no import: '.$e->getMessage()]); exit;
    }
    $zip->close();
    echo json_encode(['ok'=>true,'imported'=>$imported,'updated'=>$updated,'skipped'=>$skipped,'errors'=>$errors]);
    exit;
}

// ── qty_change ────────────────────────────────────────────────
if ($action === 'qty_change' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $d     = json_decode(file_get_contents('php://input'), true) ?? [];
    $id    = (int)($d['id']    ?? 0);
    $delta = (int)($d['delta'] ?? 0);
    if (!$id || $delta === 0) { echo json_encode(['error'=>'Parâmetros inválidos']); exit; }

    $item = $pdo->query("SELECT id, quantidade FROM inventario_items WHERE id=$id")->fetch(PDO::FETCH_ASSOC);
    if (!$item) { echo json_encode(['error'=>'Item não encontrado']); exit; }

    $qAntes  = $item['quantidade'] ?? '0';
    $numAntes = (int) preg_replace('/[^0-9\-]/', '', $qAntes);
    $numDepois = max(0, $numAntes + $delta);
    $qDepois  = (string)$numDepois;

    $pdo->prepare("UPDATE inventario_items SET quantidade=?, updated_at=NOW() WHERE id=?")->execute([$qDepois, $id]);
    $pdo->prepare("INSERT INTO inventario_movimentos (item_id, user_id, username, delta, qty_antes, qty_depois) VALUES (?,?,?,?,?,?)")
        ->execute([$id, $cur_uid, $cur_user, $delta, $qAntes, $qDepois]);

    echo json_encode(['ok'=>true, 'qty_depois'=>$qDepois]);
    exit;
}

// ── history ───────────────────────────────────────────────────
if ($action === 'history') {
    $id = (int)($_GET['id'] ?? 0);
    if (!$id) { echo json_encode([]); exit; }
    $rows = $pdo->prepare("SELECT delta, qty_antes, qty_depois, username, notas, criado_em FROM inventario_movimentos WHERE item_id=? ORDER BY criado_em DESC LIMIT 50");
    $rows->execute([$id]);
    echo json_encode($rows->fetchAll(PDO::FETCH_ASSOC));
    exit;
}

// ── add_armario ───────────────────────────────────────────────
if ($action === 'add_armario' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $d    = json_decode(file_get_contents('php://input'), true) ?? [];
    $nome = strtoupper(trim($d['nome'] ?? ''));
    if (!$nome || !preg_match('/^[A-Z]\d+$/', $nome)) { echo json_encode(['error'=>'Nome inválido. Usa letra + número (ex: A9, B1).']); exit; }
    if (strlen($nome) > 10) { echo json_encode(['error'=>'Nome demasiado longo.']); exit; }
    try {
        $ord = (int)$pdo->query("SELECT COALESCE(MAX(ordem),0)+1 FROM inventario_armarios")->fetchColumn();
        $pdo->prepare("INSERT INTO inventario_armarios (nome, ordem) VALUES (?,?)")->execute([$nome, $ord]);
        echo json_encode(['ok'=>true,'nome'=>$nome]);
    } catch (Exception $e) {
        if (str_contains($e->getMessage(), 'Duplicate') || str_contains($e->getMessage(), 'unique'))
            echo json_encode(['error'=>"O armário '$nome' já existe."]);
        else
            echo json_encode(['error'=>'Erro ao criar: '.$e->getMessage()]);
    }
    exit;
}

echo json_encode(['error'=>'Acção desconhecida']);
