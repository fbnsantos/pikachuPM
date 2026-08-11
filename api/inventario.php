<?php
// api/inventario.php — AJAX endpoint para o módulo de inventário
session_start();
if (!isset($_SESSION['username'])) { http_response_code(401); echo json_encode(['error'=>'Não autenticado']); exit; }

include_once __DIR__ . '/../config.php';

try {
    $pdo = new PDO("mysql:host=$db_host;dbname=$db_name;charset=utf8mb4", $db_user, $db_pass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
} catch (Exception $e) {
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Erro de ligação BD']); exit;
}

$cur_uid  = (int)($_SESSION['user_id'] ?? 0);
$ARMARIOS = ['A1','A2','A3','A4','A5','A6','A7','A8'];
$action   = $_GET['action'] ?? '';

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

// ── export ────────────────────────────────────────────────────
if ($action === 'export') {
    include_once __DIR__ . '/../tabs/inventario.php';
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

echo json_encode(['error'=>'Acção desconhecida']);
