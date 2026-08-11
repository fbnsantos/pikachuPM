<?php
// tabs/inventario.php — Inventário de Armários
if (!isset($_SESSION['username'])) { header('Location: login.php'); exit; }

include_once __DIR__ . '/../config.php';

try {
    $pdo = new PDO("mysql:host=$db_host;dbname=$db_name;charset=utf8mb4", $db_user, $db_pass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
} catch (Exception $e) {
    die('<div class="alert alert-danger">Erro de conexão BD</div>');
}

$pdo->exec("CREATE TABLE IF NOT EXISTS inventario_items (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    armario     VARCHAR(5)   NOT NULL,
    descricao   TEXT         NOT NULL,
    quantidade  VARCHAR(50)  DEFAULT NULL,
    prateleira  VARCHAR(100) DEFAULT NULL,
    caixa       VARCHAR(100) DEFAULT NULL,
    projeto     VARCHAR(200) DEFAULT NULL,
    link        TEXT         DEFAULT NULL,
    notas       TEXT         DEFAULT NULL,
    last_edited DATE         DEFAULT NULL,
    created_by  INT          DEFAULT NULL,
    created_at  TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    updated_at  TIMESTAMP    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_armario (armario),
    INDEX idx_descricao (descricao(100))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$pdo->exec("CREATE TABLE IF NOT EXISTS inventario_movimentos (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    item_id    INT NOT NULL,
    user_id    INT DEFAULT NULL,
    username   VARCHAR(100) DEFAULT NULL,
    delta      INT NOT NULL,
    qty_antes  VARCHAR(50) DEFAULT NULL,
    qty_depois VARCHAR(50) DEFAULT NULL,
    notas      VARCHAR(200) DEFAULT NULL,
    criado_em  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_item (item_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$pdo->exec("CREATE TABLE IF NOT EXISTS inventario_armarios (
    id        INT AUTO_INCREMENT PRIMARY KEY,
    nome      VARCHAR(20) NOT NULL UNIQUE,
    ordem     INT DEFAULT 0,
    criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// Seed armários por defeito se a tabela estiver vazia
$cnt = (int)$pdo->query("SELECT COUNT(*) FROM inventario_armarios")->fetchColumn();
if ($cnt === 0) {
    $ins = $pdo->prepare("INSERT IGNORE INTO inventario_armarios (nome, ordem) VALUES (?,?)");
    foreach (['A1','A2','A3','A4','A5','A6','A7','A8'] as $i => $a) $ins->execute([$a, $i]);
}

$cur_uid  = (int)($_SESSION['user_id'] ?? 0);
$ARMARIOS = $pdo->query("SELECT nome FROM inventario_armarios ORDER BY ordem, nome")->fetchAll(PDO::FETCH_COLUMN);
$action   = $_GET['action'] ?? '';

// ── AJAX: list ──────────────────────────────────────────────
if ($action === 'list') {
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json');
    $arm  = $_GET['armario'] ?? '';
    $q    = trim($_GET['q'] ?? '');
    $sql  = 'SELECT * FROM inventario_items WHERE 1=1';
    $p    = [];
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

// ── AJAX: save ──────────────────────────────────────────────
if ($action === 'save' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json');
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

// ── AJAX: delete ────────────────────────────────────────────
if ($action === 'delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json');
    $d  = json_decode(file_get_contents('php://input'), true) ?? [];
    $id = (int)($d['id'] ?? 0);
    if ($id) $pdo->prepare('DELETE FROM inventario_items WHERE id=?')->execute([$id]);
    echo json_encode(['ok'=>true]);
    exit;
}

// ── AJAX: export xlsx ────────────────────────────────────────
if ($action === 'export') {
    while (ob_get_level()) ob_end_clean();
    $arm  = $_GET['armario'] ?? '';
    $arms = ($arm && in_array($arm, $ARMARIOS, true)) ? [$arm] : $ARMARIOS;
    $sheets = [];
    foreach ($arms as $a) {
        $rows = $pdo->query("SELECT descricao,quantidade,prateleira,caixa,projeto,link,notas,last_edited FROM inventario_items WHERE armario='$a' ORDER BY descricao")->fetchAll(PDO::FETCH_ASSOC);
        $data = [['Descrição','Quantidade','Prateleira','Caixa','Projeto','Link','Notas','Última edição']];
        foreach ($rows as $r) $data[] = array_values($r);
        $sheets[$a] = $data;
    }
    inv_stream_xlsx($sheets, 'Inventario_' . date('Ymd') . '.xlsx');
    exit;
}

// ── AJAX: import xlsx ────────────────────────────────────────
if ($action === 'import' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    while (ob_get_level()) ob_end_clean();
    set_time_limit(120);
    ini_set('memory_limit', '256M');
    $dbg = __DIR__ . '/inv_debug.log';
    $lg = function(string $msg) use ($dbg) { file_put_contents($dbg, date('H:i:s')." $msg\n", FILE_APPEND); };
    header('Content-Type: application/json');
    if (empty($_FILES['file']['tmp_name'])) { echo json_encode(['error'=>'Nenhum ficheiro recebido']); exit; }
    $importFile = $_FILES['file']['tmp_name'];
    $lg("start file=$importFile size=" . filesize($importFile));
    $result = inv_import_xlsx($importFile, $pdo, $cur_uid, $ARMARIOS, $lg);
    $lg("done: " . json_encode($result));
    echo json_encode($result);
    exit;
}

// ════════════════════════════════════════════════════════════
// XLSX HELPERS
// ════════════════════════════════════════════════════════════

function inv_col_letter(int $col): string {
    $s = '';
    for ($c = $col; $c >= 0; $c = intval($c / 26) - 1)
        $s = chr(65 + ($c % 26)) . $s;
    return $s;
}

function inv_stream_xlsx(array $sheets, string $filename): void {
    $strings = [];
    $strIdx  = function(string $s) use (&$strings): int {
        $k = array_search($s, $strings, true);
        if ($k === false) { $strings[] = $s; return count($strings) - 1; }
        return $k;
    };

    $names     = array_keys($sheets);
    $sheetXmls = [];
    foreach ($names as $si => $sname) {
        $x = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
           . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>';
        foreach ($sheets[$sname] as $ri => $row) {
            $x .= '<row r="'.($ri+1).'">';
            foreach ($row as $ci => $val) {
                $ref = inv_col_letter($ci).($ri+1);
                if ($val === null || $val === '') { $x .= '<c r="'.$ref.'"/>'; continue; }
                if (is_numeric($val) && !preg_match('/^0\d/', $val))
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
    $zip->addFromString('[Content_Types].xml',        $ct);
    $zip->addFromString('_rels/.rels',                $rels);
    $zip->addFromString('xl/workbook.xml',            $wb);
    $zip->addFromString('xl/_rels/workbook.xml.rels', $wbr);
    $zip->addFromString('xl/sharedStrings.xml',       $ss);
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

// Lê shared strings com XMLReader (streaming — não carrega DOM em memória)
function inv_read_shared_strings(string $raw): array {
    $strings = [];
    $xr = new XMLReader();
    $xr->XML($raw);
    unset($raw);
    $current = ''; $inT = false;
    while ($xr->read()) {
        if ($xr->nodeType === XMLReader::ELEMENT) {
            if ($xr->localName === 'si')  $current = '';
            elseif ($xr->localName === 't') $inT = true;
        } elseif ($xr->nodeType === XMLReader::TEXT && $inT) {
            $current .= $xr->value;
        } elseif ($xr->nodeType === XMLReader::END_ELEMENT) {
            if ($xr->localName === 'si') { $strings[] = $current; $current = ''; }
            elseif ($xr->localName === 't') $inT = false;
        }
    }
    $xr->close();
    return $strings;
}

function inv_read_worksheet_rows(string $raw, array $strings): array {
    $rows = []; $cells = []; $rowNum = 0;
    $curCol = 0; $curType = ''; $curVal = ''; $inV = false;

    $xr = new XMLReader();
    $xr->XML($raw);
    unset($raw);
    while ($xr->read()) {
        if ($xr->nodeType === XMLReader::ELEMENT) {
            $ln = $xr->localName;
            if ($ln === 'row') {
                $cells = []; $rowNum++;
            } elseif ($ln === 'c') {
                $ref = $xr->getAttribute('r') ?? '';
                preg_match('/^([A-Z]+)/', $ref, $cm);
                $col = $cm[1] ?? 'A'; $curCol = 0;
                for ($i = 0; $i < strlen($col); $i++)
                    $curCol = $curCol * 26 + (ord($col[$i]) - 64);
                $curCol--;
                $curType = $xr->getAttribute('t') ?? '';
                $curVal = ''; $inV = false;
            } elseif ($ln === 'v') {
                $inV = true; $curVal = '';
            }
        } elseif (($xr->nodeType === XMLReader::TEXT || $xr->nodeType === XMLReader::CDATA) && $inV) {
            $curVal .= $xr->value;
        } elseif ($xr->nodeType === XMLReader::END_ELEMENT) {
            $ln = $xr->localName;
            if ($ln === 'v') {
                $inV = false;
                $v = $curVal;
                if ($curType === 's') $v = $strings[(int)$v] ?? '';
                $cells[$curCol] = $v;
            } elseif ($ln === 'row') {
                if ($rowNum > 1) $rows[] = $cells; // skip header row
            }
        }
    }
    $xr->close();
    return $rows;
}

function inv_import_xlsx(string $filepath, PDO $pdo, int $uid, array $armarios, callable $lg = null): array {
    $lg = $lg ?? function(string $s) {};
    $zip = new ZipArchive();
    $opened = $zip->open($filepath);
    $lg("zip->open=$opened numFiles={$zip->numFiles}");
    if ($opened !== true) return ['error'=>'Não foi possível abrir o ficheiro XLSX (código '.$opened.')'];

    $ssRaw   = $zip->getFromName('xl/sharedStrings.xml') ?: '';
    $lg("sharedStrings len=" . strlen($ssRaw));
    $strings = $ssRaw ? inv_read_shared_strings($ssRaw) : [];
    $lg("strings count=" . count($strings));
    unset($ssRaw);

    $wbRaw  = $zip->getFromName('xl/workbook.xml')           ?? '';
    $wbRRaw = $zip->getFromName('xl/_rels/workbook.xml.rels') ?? '';
    $lg("workbook len=" . strlen($wbRaw) . " wbRels len=" . strlen($wbRRaw));
    $rIdToFile = [];
    preg_match_all('/Id="([^"]+)"[^>]+Target="([^"]+)"/', $wbRRaw, $rm, PREG_SET_ORDER);
    foreach ($rm as $m) $rIdToFile[$m[1]] = $m[2];
    $sheetFile = [];
    preg_match_all('/<sheet\b[^>]+>/i', $wbRaw, $sm);
    foreach ($sm[0] as $tag) {
        if (!preg_match('/name="([^"]+)"/', $tag, $nm)) continue;
        if (!preg_match('/r:id="([^"]+)"/', $tag, $ri)) continue;
        $f = $rIdToFile[$ri[1]] ?? '';
        if ($f) $sheetFile[$nm[1]] = (strpos($f,'/') === 0) ? ltrim($f,'/') : 'xl/'.$f;
    }
    unset($wbRaw, $wbRRaw);

    // Pre-carregar existentes (1 query total)
    $existing = [];
    foreach ($pdo->query("SELECT id, armario, descricao FROM inventario_items")->fetchAll(PDO::FETCH_ASSOC) as $r)
        $existing[$r['armario'].'||'.$r['descricao']] = (int)$r['id'];

    $stUpdate = $pdo->prepare('UPDATE inventario_items SET quantidade=?,prateleira=?,caixa=?,projeto=?,last_edited=?,updated_at=NOW() WHERE id=?');
    $stInsert = $pdo->prepare('INSERT INTO inventario_items (armario,descricao,quantidade,prateleira,caixa,projeto,last_edited,created_by) VALUES (?,?,?,?,?,?,?,?)');

    $imported = 0; $updated = 0; $skipped = 0; $errors = [];

    $lg("sheetFile map: " . json_encode($sheetFile));
    $pdo->beginTransaction();
    try {
        foreach ($armarios as $arm) {
            if (!isset($sheetFile[$arm])) { $errors[] = "Folha '$arm' não encontrada"; $lg("arm=$arm NOT in sheetFile"); continue; }
            $lg("reading arm=$arm path={$sheetFile[$arm]}");
            $wsRaw = $zip->getFromName($sheetFile[$arm]);
            if (!$wsRaw) { $errors[] = "Erro ao ler '$arm'"; $lg("arm=$arm getFromName failed"); continue; }
            $lg("arm=$arm wsRaw len=" . strlen($wsRaw));
            $rows = inv_read_worksheet_rows($wsRaw, $strings);
            $lg("arm=$arm rows=" . count($rows));
            unset($wsRaw);

            foreach ($rows as $cells) {
                $desc = trim($cells[1] ?? '');
                if (!$desc) { $skipped++; continue; }

                $qty  = trim($cells[2] ?? '');
                $prat = trim($cells[3] ?? '');
                $cx   = trim($cells[4] ?? '');
                $proj = trim($cells[5] ?? '');
                $led  = null;
                $lr   = $cells[6] ?? '';
                if ($lr !== '' && is_numeric($lr) && (float)$lr > 40000)
                    $led = date('Y-m-d', (int)(((float)$lr - 25569) * 86400));

                $key = $arm.'||'.$desc;
                if (isset($existing[$key])) {
                    $stUpdate->execute([$qty,$prat,$cx,$proj,$led,$existing[$key]]);
                    $updated++;
                } else {
                    $stInsert->execute([$arm,$desc,$qty,$prat,$cx,$proj,$led,$uid]);
                    $existing[$key] = (int)$pdo->lastInsertId();
                    $imported++;
                }
            }
        }
        $pdo->commit();
    } catch (Exception $e) {
        $pdo->rollBack();
        return ['error' => 'Erro durante import: ' . $e->getMessage()];
    }

    $zip->close();
    return ['ok'=>true,'imported'=>$imported,'updated'=>$updated,'skipped'=>$skipped,'errors'=>$errors];
}

// ── Stats para UI ────────────────────────────────────────────
$armCounts = array_fill_keys($ARMARIOS, 0);
foreach ($pdo->query("SELECT armario, COUNT(*) AS c FROM inventario_items GROUP BY armario")->fetchAll(PDO::FETCH_ASSOC) as $r)
    $armCounts[$r['armario']] = (int)$r['c'];
$totalItems   = array_sum($armCounts);
$totalArmarios = count($ARMARIOS);
$totalProjects = (int)$pdo->query("SELECT COUNT(DISTINCT projeto) FROM inventario_items WHERE projeto IS NOT NULL AND projeto <> ''")->fetchColumn();
?>

<style>
/* ── Layout ─────────────────────────────────────────────────── */
.inv-wrap{display:flex;flex-direction:column;gap:0;height:calc(100vh - 120px);min-height:400px}

/* ── Stats header ────────────────────────────────────────────── */
.inv-stats{display:flex;gap:12px;flex-wrap:wrap;margin-bottom:14px;flex-shrink:0}
.inv-stat-card{flex:1;min-width:120px;background:#fff;border:1px solid #e9ecef;border-radius:12px;padding:14px 18px;display:flex;align-items:center;gap:12px;box-shadow:0 1px 3px rgba(0,0,0,.05)}
.inv-stat-icon{width:40px;height:40px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:18px;flex-shrink:0}
.inv-stat-icon.blue{background:#e8f0fe}.inv-stat-icon.green{background:#e6f4ea}.inv-stat-icon.purple{background:#f3e8fd}.inv-stat-icon.orange{background:#fff3e0}
.inv-stat-val{font-size:22px;font-weight:700;color:#212529;line-height:1}
.inv-stat-lbl{font-size:11px;color:#6c757d;font-weight:500;margin-top:2px}

/* ── Toolbar ─────────────────────────────────────────────────── */
.inv-toolbar{display:flex;align-items:center;gap:8px;padding:0 0 10px;flex-wrap:wrap;flex-shrink:0}
.inv-toolbar-right{display:flex;align-items:center;gap:6px;margin-left:auto;flex-wrap:wrap}
.inv-search{flex:1;min-width:200px;max-width:360px;position:relative}
.inv-search input{width:100%;padding:8px 12px 8px 36px;border:1px solid #dee2e6;border-radius:8px;font-size:13px;outline:none;transition:border-color .15s;background:#fff}
.inv-search input:focus{border-color:#0d6efd;box-shadow:0 0 0 3px rgba(13,110,253,.08)}
.inv-search .inv-s-icon{position:absolute;left:11px;top:50%;transform:translateY(-50%);color:#adb5bd;font-size:14px}

/* ── Armário tabs ─────────────────────────────────────────────── */
.inv-armtabs{display:flex;gap:4px;flex-wrap:wrap;flex-shrink:0;padding-bottom:10px;border-bottom:2px solid #e9ecef;margin-bottom:0;align-items:center}
.inv-atab{padding:5px 14px;border:1px solid #dee2e6;border-radius:20px;font-size:12px;font-weight:600;cursor:pointer;background:#fff;color:#495057;transition:all .15s;display:flex;align-items:center;gap:5px;white-space:nowrap}
.inv-atab:hover{border-color:#0d6efd;color:#0d6efd;background:#f0f4ff}
.inv-atab.active{background:#0d6efd;border-color:#0d6efd;color:#fff}
.inv-atab .inv-cnt{background:rgba(0,0,0,.12);border-radius:10px;padding:1px 6px;font-size:10px}
.inv-atab.active .inv-cnt{background:rgba(255,255,255,.25)}
.inv-atab-add{padding:5px 10px;border:1px dashed #ced4da;border-radius:20px;font-size:13px;font-weight:600;cursor:pointer;background:transparent;color:#6c757d;transition:all .15s}
.inv-atab-add:hover{border-color:#0d6efd;color:#0d6efd;border-style:solid}

/* ── Table ────────────────────────────────────────────────────── */
.inv-table-wrap{flex:1;overflow:auto;border:1px solid #e9ecef;border-radius:10px;margin-top:10px}
.inv-table-wrap table{width:100%;border-collapse:collapse;font-size:13px}
.inv-table-wrap thead th{background:#f8f9fa;padding:9px 12px;text-align:left;font-weight:600;font-size:11px;text-transform:uppercase;letter-spacing:.4px;color:#6c757d;border-bottom:2px solid #e9ecef;white-space:nowrap;position:sticky;top:0;z-index:2}
.inv-table-wrap tbody td{padding:8px 12px;border-bottom:1px solid #f5f5f5;vertical-align:middle}
.inv-table-wrap tbody tr:last-child td{border-bottom:none}
.inv-table-wrap tbody tr:hover{background:#fafbff}
.inv-desc{font-weight:500;color:#212529;max-width:280px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.inv-arm-badge{display:inline-block;padding:2px 8px;border-radius:12px;font-size:10px;font-weight:700;background:#e8f0fe;color:#1a56db}
.inv-qty{font-weight:700;color:#212529}
.inv-meta{color:#6c757d;font-size:12px}
.inv-actions{display:flex;gap:4px;opacity:0;transition:opacity .15s}
tr:hover .inv-actions{opacity:1}
.inv-empty{text-align:center;padding:60px 20px;color:#6c757d}
.inv-empty-icon{font-size:48px;opacity:.35;margin-bottom:8px}
.inv-loading{text-align:center;padding:40px;color:#adb5bd}

/* ── Modals ───────────────────────────────────────────────────── */
.inv-modal .modal-dialog{max-width:600px}
.inv-modal .form-label{font-size:12px;font-weight:600;color:#6c757d;text-transform:uppercase;letter-spacing:.4px}
.inv-modal .form-control,.inv-modal .form-select{font-size:13px}
.inv-form-row{display:grid;grid-template-columns:1fr 1fr;gap:12px}
.inv-drop{border:2px dashed #dee2e6;border-radius:10px;padding:32px;text-align:center;cursor:pointer;transition:all .2s;background:#fafafa}
.inv-drop:hover,.inv-drop.over{border-color:#0d6efd;background:#f0f4ff}
.inv-drop-icon{font-size:40px;margin-bottom:8px;opacity:.5}
.inv-import-result{background:#f8f9fa;border-radius:8px;padding:12px;font-size:13px;margin-top:12px;display:none}

/* ── Qty buttons ─────────────────────────────────────────────── */
.inv-qty-btn{width:22px;height:22px;border:1px solid #dee2e6;border-radius:6px;background:#fff;color:#495057;font-size:14px;line-height:1;cursor:pointer;display:flex;align-items:center;justify-content:center;padding:0;transition:all .12s;flex-shrink:0}
.inv-qty-btn:hover{background:#f0f4ff;border-color:#0d6efd;color:#0d6efd}
.inv-qty-plus:hover{background:#e6f4ea;border-color:#198754;color:#198754}
.inv-qty-minus:hover{background:#fdecea;border-color:#dc3545;color:#dc3545}
.inv-qty-btn:active{transform:scale(.88)}
.inv-qty-val{min-width:28px;text-align:center;font-weight:700;font-size:13px}

/* ── History modal ───────────────────────────────────────────── */
.inv-hist-list{max-height:380px;overflow-y:auto}
.inv-hist-item{display:flex;align-items:flex-start;gap:10px;padding:10px 0;border-bottom:1px solid #f0f0f0}
.inv-hist-item:last-child{border-bottom:none}
.inv-hist-badge{width:36px;height:36px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:16px;font-weight:700;flex-shrink:0}
.inv-hist-badge.plus{background:#e6f4ea;color:#198754}
.inv-hist-badge.minus{background:#fdecea;color:#dc3545}
.inv-hist-meta{font-size:11px;color:#6c757d;margin-top:2px}
.inv-hist-delta{font-weight:700;font-size:13px}
.inv-hist-delta.plus{color:#198754}.inv-hist-delta.minus{color:#dc3545}
.inv-hist-qty{font-size:12px;color:#6c757d;margin-top:1px}
.inv-hist-empty{text-align:center;padding:32px;color:#adb5bd;font-size:13px}

@media(max-width:600px){.inv-form-row{grid-template-columns:1fr}.inv-toolbar-right{width:100%}.inv-stat-card{min-width:calc(50% - 6px)}}
</style>

<!-- Stats -->
<div class="inv-wrap">
  <div class="inv-stats">
    <div class="inv-stat-card">
      <div class="inv-stat-icon blue">📦</div>
      <div>
        <div class="inv-stat-val" id="inv-stat-total"><?= $totalItems ?></div>
        <div class="inv-stat-lbl">Itens totais</div>
      </div>
    </div>
    <div class="inv-stat-card">
      <div class="inv-stat-icon green">🗄️</div>
      <div>
        <div class="inv-stat-val"><?= $totalArmarios ?></div>
        <div class="inv-stat-lbl">Armários</div>
      </div>
    </div>
    <div class="inv-stat-card">
      <div class="inv-stat-icon purple">🔬</div>
      <div>
        <div class="inv-stat-val"><?= $totalProjects ?></div>
        <div class="inv-stat-lbl">Projetos</div>
      </div>
    </div>
    <div class="inv-stat-card" style="flex:2;min-width:220px">
      <div class="inv-stat-icon orange">🔍</div>
      <div style="flex:1">
        <div class="inv-search" style="max-width:100%;margin:0">
          <span class="inv-s-icon">🔍</span>
          <input type="text" id="inv-search-inp" placeholder="Pesquisar em todos os armários...">
        </div>
      </div>
    </div>
  </div>

  <!-- Toolbar -->
  <div class="inv-toolbar">
    <div class="inv-toolbar-right" style="margin-left:0;width:100%;justify-content:flex-end">
      <button class="btn btn-sm btn-outline-secondary" id="inv-btn-import">📥 Importar</button>
      <button class="btn btn-sm btn-outline-secondary" id="inv-btn-export">📤 Exportar</button>
      <button class="btn btn-sm btn-primary" id="inv-btn-add">+ Adicionar Item</button>
    </div>
  </div>

  <!-- Armário tabs -->
  <div class="inv-armtabs" id="inv-armtabs">
    <button class="inv-atab active" data-arm="">
      Todos <span class="inv-cnt" id="inv-cnt-all"><?= $totalItems ?></span>
    </button>
    <?php foreach ($ARMARIOS as $arm): ?>
    <button class="inv-atab" data-arm="<?= $arm ?>">
      <?= htmlspecialchars($arm) ?> <span class="inv-cnt"><?= $armCounts[$arm] ?? 0 ?></span>
    </button>
    <?php endforeach; ?>
    <button class="inv-atab-add" id="inv-btn-new-arm" title="Adicionar novo armário">＋</button>
  </div>

  <!-- Table -->
  <div class="inv-table-wrap">
    <table>
      <thead>
        <tr>
          <th>Armário</th>
          <th>Descrição</th>
          <th>Qty</th>
          <th>Prateleira</th>
          <th>Caixa</th>
          <th>Projeto</th>
          <th>Última Edição</th>
          <th></th>
        </tr>
      </thead>
      <tbody id="inv-tbody">
        <tr><td colspan="8" class="inv-loading">↻ A carregar...</td></tr>
      </tbody>
    </table>
  </div>
</div>

<!-- ── Modal: Add / Edit ── -->
<div class="modal fade inv-modal" id="invModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="invModalTitle">Novo Item</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <input type="hidden" id="inv-id">
        <div class="mb-3">
          <label class="form-label">Armário <span class="text-danger">*</span></label>
          <select class="form-select form-select-sm" id="inv-armario">
            <?php foreach ($ARMARIOS as $arm): ?>
            <option value="<?= $arm ?>"><?= $arm ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="mb-3">
          <label class="form-label">Descrição <span class="text-danger">*</span></label>
          <input type="text" class="form-control form-control-sm" id="inv-descricao" placeholder="Nome do item">
        </div>
        <div class="inv-form-row mb-3">
          <div>
            <label class="form-label">Quantidade</label>
            <input type="text" class="form-control form-control-sm" id="inv-quantidade" placeholder="ex: 5">
          </div>
          <div>
            <label class="form-label">Prateleira</label>
            <input type="text" class="form-control form-control-sm" id="inv-prateleira" placeholder="ex: 1">
          </div>
        </div>
        <div class="inv-form-row mb-3">
          <div>
            <label class="form-label">Caixa</label>
            <input type="text" class="form-control form-control-sm" id="inv-caixa" placeholder="ex: cinza">
          </div>
          <div>
            <label class="form-label">Projeto</label>
            <input type="text" class="form-control form-control-sm" id="inv-projeto" placeholder="Projeto associado">
          </div>
        </div>
        <div class="mb-3">
          <label class="form-label">Link</label>
          <input type="url" class="form-control form-control-sm" id="inv-link" placeholder="https://...">
        </div>
        <div class="mb-3">
          <label class="form-label">Notas</label>
          <textarea class="form-control form-control-sm" id="inv-notas" rows="2" placeholder="Observações..."></textarea>
        </div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Cancelar</button>
        <button class="btn btn-sm btn-primary" id="inv-btn-save">Guardar</button>
      </div>
    </div>
  </div>
</div>

<!-- ── Modal: Novo Armário ── -->
<div class="modal fade" id="invNewArmModal" tabindex="-1">
  <div class="modal-dialog modal-sm">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Novo Armário</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <label class="form-label" style="font-size:12px;font-weight:600;color:#6c757d;text-transform:uppercase;letter-spacing:.4px">Nome (ex: A9, A10, B1)</label>
        <input type="text" class="form-control" id="inv-new-arm-nome" placeholder="Axxx" maxlength="10" style="text-transform:uppercase;font-size:15px;font-weight:700;letter-spacing:1px">
        <div class="text-danger mt-2" id="inv-new-arm-err" style="font-size:12px;display:none"></div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Cancelar</button>
        <button class="btn btn-sm btn-primary" id="inv-btn-create-arm">Criar Armário</button>
      </div>
    </div>
  </div>
</div>

<!-- ── Modal: Import ── -->
<div class="modal fade" id="invImportModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Importar XLSX</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <p class="text-muted" style="font-size:13px">
          Seleciona um ficheiro XLSX com folhas A1–A8 (colunas: Descrição, Quantidade, Prateleira, Caixa, Projeto).
          Itens existentes com a mesma descrição serão actualizados; novos serão criados.
        </p>
        <div class="inv-drop" id="inv-drop-zone">
          <div class="inv-drop-icon">📂</div>
          <div><strong>Arrasta o ficheiro aqui</strong> ou clica para seleccionar</div>
          <div class="text-muted mt-1" style="font-size:12px">.xlsx</div>
          <input type="file" id="inv-file-input" accept=".xlsx" style="display:none">
        </div>
        <div class="inv-import-result" id="inv-import-result"></div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Fechar</button>
        <button class="btn btn-sm btn-success" id="inv-btn-do-import" disabled>📥 Importar</button>
      </div>
    </div>
  </div>
</div>

<!-- ── Modal: Histórico ── -->
<div class="modal fade" id="invHistModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="invHistTitle">Histórico</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body p-0">
        <div id="inv-hist-body" class="inv-hist-list px-3 py-2">
          <div class="inv-hist-empty">A carregar...</div>
        </div>
      </div>
      <div class="modal-footer justify-content-start">
        <small class="text-muted" id="inv-hist-note">Últimos 50 movimentos</small>
        <button class="btn btn-sm btn-secondary ms-auto" data-bs-dismiss="modal">Fechar</button>
      </div>
    </div>
  </div>
</div>

<!-- Review Import Modal -->
<div class="modal fade" id="invReviewModal" tabindex="-1" data-bs-backdrop="static" data-bs-keyboard="false">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header py-2">
        <h6 class="modal-title mb-0">📋 Validar Importação</h6>
        <button type="button" class="btn-close" id="inv-rev-cancel-btn"></button>
      </div>
      <div class="modal-body">
        <div class="d-flex justify-content-between align-items-center mb-3">
          <small class="text-muted" id="inv-rev-progress">Alteração 1 de N</small>
          <small class="text-muted" id="inv-rev-accepted-count">0 aceites</small>
        </div>
        <div id="inv-rev-card" class="border rounded p-3 bg-light"></div>
        <div class="progress mt-3" style="height:4px">
          <div class="progress-bar" id="inv-rev-bar" style="width:0%"></div>
        </div>
      </div>
      <div class="modal-footer justify-content-between py-2">
        <button class="btn btn-sm btn-outline-secondary" id="inv-rev-skip">Saltar →</button>
        <div class="d-flex gap-2">
          <button class="btn btn-sm btn-outline-warning" id="inv-rev-skip-all">Saltar todos</button>
          <button class="btn btn-sm btn-success" id="inv-rev-accept">✅ Aceitar</button>
        </div>
      </div>
    </div>
  </div>
</div>

<script>
(function(){
'use strict';

let currentArm = '';
let allItems   = [];
let searchQ    = '';
let _modal, _importModal, _newArmModal, _histModal, _reviewModal;

// ── Init ─────────────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', () => {
  _modal       = new bootstrap.Modal(document.getElementById('invModal'));
  _importModal = new bootstrap.Modal(document.getElementById('invImportModal'));
  _newArmModal = new bootstrap.Modal(document.getElementById('invNewArmModal'));
  _histModal   = new bootstrap.Modal(document.getElementById('invHistModal'));
  _reviewModal = new bootstrap.Modal(document.getElementById('invReviewModal'));
  document.getElementById('inv-rev-accept')  .addEventListener('click', () => advanceReview(true));
  document.getElementById('inv-rev-skip')    .addEventListener('click', () => advanceReview(false));
  document.getElementById('inv-rev-skip-all').addEventListener('click', () => finishReview());
  document.getElementById('inv-rev-cancel-btn').addEventListener('click', () => { _reviewModal.hide(); resetImportBtn(); });

  // Qty +/− (event delegation)
  document.getElementById('inv-tbody').addEventListener('click', e => {
    const btn = e.target.closest('.inv-qty-btn');
    if (!btn) return;
    const id    = parseInt(btn.dataset.id);
    const delta = btn.classList.contains('inv-qty-plus') ? 1 : -1;
    qtyChange(id, delta);
  });

  // Armário tabs (delegated — tabs can be added dynamically)
  document.getElementById('inv-armtabs').addEventListener('click', e => {
    const btn = e.target.closest('.inv-atab');
    if (!btn) return;
    document.querySelectorAll('.inv-atab').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
    currentArm = btn.dataset.arm;
    loadItems();
  });

  // Novo armário
  document.getElementById('inv-btn-new-arm').addEventListener('click', () => {
    document.getElementById('inv-new-arm-nome').value = '';
    document.getElementById('inv-new-arm-err').style.display = 'none';
    _newArmModal.show();
    setTimeout(() => document.getElementById('inv-new-arm-nome').focus(), 300);
  });
  document.getElementById('inv-btn-create-arm').addEventListener('click', createArmario);
  document.getElementById('inv-new-arm-nome').addEventListener('keydown', e => { if (e.key === 'Enter') createArmario(); });

  // Search
  let sTimer;
  document.getElementById('inv-search-inp').addEventListener('input', e => {
    clearTimeout(sTimer);
    sTimer = setTimeout(() => { searchQ = e.target.value; renderTable(); }, 200);
  });

  document.getElementById('inv-btn-add').addEventListener('click', () => openModal());
  document.getElementById('inv-btn-save').addEventListener('click', saveItem);
  document.getElementById('inv-btn-export').addEventListener('click', doExport);
  document.getElementById('inv-btn-import').addEventListener('click', () => {
    document.getElementById('inv-import-result').style.display = 'none';
    document.getElementById('inv-btn-do-import').disabled = true;
    document.getElementById('inv-drop-zone').querySelector('div:nth-child(2)').textContent = 'Arrasta o ficheiro aqui ou clica para seleccionar';
    _invFile = null;
    _importModal.show();
  });

  // Drop zone
  const drop = document.getElementById('inv-drop-zone');
  const fi   = document.getElementById('inv-file-input');
  drop.addEventListener('click', () => fi.click());
  drop.addEventListener('dragover', e => { e.preventDefault(); drop.classList.add('over'); });
  drop.addEventListener('dragleave', () => drop.classList.remove('over'));
  drop.addEventListener('drop', e => {
    e.preventDefault(); drop.classList.remove('over');
    if (e.dataTransfer.files[0]) setImportFile(e.dataTransfer.files[0]);
  });
  fi.addEventListener('change', () => fi.files[0] && setImportFile(fi.files[0]));
  document.getElementById('inv-btn-do-import').addEventListener('click', doImport);

  loadItems();
});

// ── Load + render ─────────────────────────────────────────────
async function loadItems() {
  document.getElementById('inv-tbody').innerHTML = '<tr><td colspan="8" class="inv-loading">↻ A carregar...</td></tr>';
  const url = `api/inventario.php?action=list${currentArm ? '&armario='+currentArm : ''}`;
  const res = await fetch(url);
  allItems  = await res.json();
  renderTable();
}

function renderTable() {
  const q    = searchQ.toLowerCase().trim();
  const list = q ? allItems.filter(it =>
    (it.descricao||'').toLowerCase().includes(q) ||
    (it.projeto||'').toLowerCase().includes(q)   ||
    (it.caixa||'').toLowerCase().includes(q)     ||
    (it.prateleira||'').toLowerCase().includes(q) ||
    (it.notas||'').toLowerCase().includes(q)
  ) : allItems;

  const tbody = document.getElementById('inv-tbody');
  if (!list.length) {
    tbody.innerHTML = `<tr><td colspan="8" class="inv-empty"><div class="inv-empty-icon">📦</div><div>Sem itens${q?' para "'+q+'"':''}</div></td></tr>`;
    return;
  }

  tbody.innerHTML = list.map(it => `
    <tr data-id="${it.id}">
      <td><span class="inv-arm-badge">${esc(it.armario)}</span></td>
      <td class="inv-desc" title="${esc(it.descricao)}">${esc(it.descricao)}${it.link?` <a href="${esc(it.link)}" target="_blank" title="Link" style="font-size:11px">↗</a>`:''}</td>
      <td class="inv-qty">
        <div style="display:flex;align-items:center;gap:4px">
          <button class="inv-qty-btn inv-qty-minus" data-id="${it.id}" title="Remover 1">−</button>
          <span class="inv-qty-val" id="qty-${it.id}">${esc(it.quantidade||'0')}</span>
          <button class="inv-qty-btn inv-qty-plus"  data-id="${it.id}" title="Adicionar 1">＋</button>
        </div>
      </td>
      <td class="inv-meta">${it.prateleira||'—'}</td>
      <td class="inv-meta">${it.caixa||'—'}</td>
      <td class="inv-meta">${it.projeto?`<span style="background:#e8f4ea;color:#155724;border-radius:10px;padding:1px 8px;font-size:11px;font-weight:600">${esc(it.projeto)}</span>`:'—'}</td>
      <td class="inv-meta">${it.last_edited||'—'}</td>
      <td>
        <div class="inv-actions">
          <button class="btn btn-xs btn-outline-info"      onclick="invHistory(${it.id},'${esc(it.descricao)}')" title="Histórico de movimentos">👁</button>
          <button class="btn btn-xs btn-outline-secondary" onclick="invEdit(${it.id})" title="Editar">✏️</button>
          <button class="btn btn-xs btn-outline-danger"    onclick="invDelete(${it.id},'${esc(it.descricao)}')" title="Eliminar">🗑</button>
        </div>
      </td>
    </tr>`).join('');
}

function esc(s){ return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }

// ── Modal ─────────────────────────────────────────────────────
function openModal(item = null) {
  document.getElementById('invModalTitle').textContent = item ? 'Editar Item' : 'Novo Item';
  document.getElementById('inv-id').value         = item ? item.id        : '';
  document.getElementById('inv-armario').value    = item ? item.armario   : (currentArm || 'A1');
  document.getElementById('inv-descricao').value  = item ? item.descricao : '';
  document.getElementById('inv-quantidade').value = item ? (item.quantidade||'') : '';
  document.getElementById('inv-prateleira').value = item ? (item.prateleira||'') : '';
  document.getElementById('inv-caixa').value      = item ? (item.caixa||'')      : '';
  document.getElementById('inv-projeto').value    = item ? (item.projeto||'')    : '';
  document.getElementById('inv-link').value       = item ? (item.link||'')       : '';
  document.getElementById('inv-notas').value      = item ? (item.notas||'')      : '';
  _modal.show();
  setTimeout(() => document.getElementById('inv-descricao').focus(), 300);
}

window.invEdit = function(id) {
  const it = allItems.find(i => i.id == id);
  if (it) openModal(it);
};

window.invDelete = async function(id, desc) {
  if (!confirm(`Eliminar "${desc}"?`)) return;
  await fetch('api/inventario.php?action=delete', {method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({id})});
  allItems = allItems.filter(i => i.id != id);
  renderTable();
  updateCount();
};

async function saveItem() {
  const btn = document.getElementById('inv-btn-save');
  const payload = {
    id:         document.getElementById('inv-id').value || null,
    armario:    document.getElementById('inv-armario').value,
    descricao:  document.getElementById('inv-descricao').value.trim(),
    quantidade: document.getElementById('inv-quantidade').value.trim(),
    prateleira: document.getElementById('inv-prateleira').value.trim(),
    caixa:      document.getElementById('inv-caixa').value.trim(),
    projeto:    document.getElementById('inv-projeto').value.trim(),
    link:       document.getElementById('inv-link').value.trim(),
    notas:      document.getElementById('inv-notas').value.trim(),
  };
  if (!payload.descricao) { alert('Descrição obrigatória'); return; }
  btn.disabled = true; btn.textContent = 'A guardar...';
  try {
    const r = await fetch('api/inventario.php?action=save', {method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(payload)});
    const d = await r.json();
    if (d.error) { alert(d.error); return; }
    _modal.hide();
    if (payload.id) {
      const idx = allItems.findIndex(i => i.id == payload.id);
      if (idx >= 0) allItems[idx] = d.item;
    } else {
      allItems.push(d.item);
    }
    renderTable();
    updateCount();
  } finally {
    btn.disabled = false; btn.textContent = 'Guardar';
  }
}

// ── Qty change ────────────────────────────────────────────────
async function qtyChange(id, delta) {
  const valEl = document.getElementById('qty-' + id);
  if (!valEl) return;
  const btns = document.querySelectorAll(`.inv-qty-btn[data-id="${id}"]`);
  btns.forEach(b => b.disabled = true);
  try {
    const r = await fetch('api/inventario.php?action=qty_change', {
      method:'POST', headers:{'Content-Type':'application/json'},
      body: JSON.stringify({id, delta})
    });
    const d = await r.json();
    if (d.error) { console.error(d.error); return; }
    valEl.textContent = d.qty_depois;
    valEl.style.transition = 'color .25s';
    valEl.style.color = delta > 0 ? '#198754' : '#dc3545';
    setTimeout(() => { valEl.style.color = ''; }, 600);
    const it = allItems.find(i => i.id == id);
    if (it) it.quantidade = d.qty_depois;
  } finally {
    btns.forEach(b => b.disabled = false);
  }
}

// ── History ───────────────────────────────────────────────────
window.invHistory = async function(id, desc) {
  document.getElementById('invHistTitle').textContent = '📋 ' + desc;
  document.getElementById('inv-hist-body').innerHTML = '<div class="inv-hist-empty">A carregar...</div>';
  _histModal.show();
  const r = await fetch(`api/inventario.php?action=history&id=${id}`);
  const d = await r.json();
  const body = document.getElementById('inv-hist-body');
  if (!d.length) { body.innerHTML = '<div class="inv-hist-empty">Sem movimentos registados.</div>'; return; }
  body.innerHTML = d.map(m => {
    const plus = m.delta > 0;
    const sign = plus ? '+' : '';
    const dt   = new Date(m.criado_em).toLocaleString('pt-PT',{day:'2-digit',month:'2-digit',year:'numeric',hour:'2-digit',minute:'2-digit'});
    return `<div class="inv-hist-item">
      <div class="inv-hist-badge ${plus?'plus':'minus'}">${plus?'＋':'−'}</div>
      <div style="flex:1">
        <div><span class="inv-hist-delta ${plus?'plus':'minus'}">${sign}${m.delta}</span>
          <span style="font-size:12px;color:#495057;margin-left:6px">por <strong>${esc(m.username||'?')}</strong></span>
        </div>
        <div class="inv-hist-qty">${m.qty_antes} → ${m.qty_depois}</div>
        ${m.notas ? `<div style="font-size:12px;color:#6c757d;margin-top:2px">📝 ${esc(m.notas)}</div>` : ''}
        <div class="inv-hist-meta">${dt}</div>
      </div>
    </div>`;
  }).join('');
};

function updateCount() {
  const tot = allItems.length;
  const allCnt = document.querySelector('.inv-atab[data-arm=""] .inv-cnt');
  if (allCnt) allCnt.textContent = tot;
  const stat = document.getElementById('inv-stat-total');
  if (stat) stat.textContent = tot;
}

// ── Novo Armário ──────────────────────────────────────────────
async function createArmario() {
  const inp  = document.getElementById('inv-new-arm-nome');
  const err  = document.getElementById('inv-new-arm-err');
  const nome = inp.value.trim().toUpperCase();
  err.style.display = 'none';
  if (!nome) { err.textContent = 'Introduz um nome.'; err.style.display = ''; return; }
  if (!/^[A-Z]\d+$/.test(nome)) { err.textContent = 'Formato inválido. Usa letra + número (ex: A9, B1, A10).'; err.style.display = ''; return; }

  const btn = document.getElementById('inv-btn-create-arm');
  btn.disabled = true; btn.textContent = 'A criar...';
  try {
    const r = await fetch('api/inventario.php?action=add_armario', {
      method:'POST', headers:{'Content-Type':'application/json'},
      body: JSON.stringify({nome})
    });
    const d = await r.json();
    if (d.error) { err.textContent = d.error; err.style.display = ''; return; }

    // Adicionar tab ao DOM
    const tabs = document.getElementById('inv-armtabs');
    const addBtn = document.getElementById('inv-btn-new-arm');
    const tab = document.createElement('button');
    tab.className = 'inv-atab';
    tab.dataset.arm = nome;
    tab.innerHTML = `${nome} <span class="inv-cnt">0</span>`;
    tabs.insertBefore(tab, addBtn);

    // Adicionar ao select do modal
    const sel = document.getElementById('inv-armario');
    const opt = document.createElement('option');
    opt.value = nome; opt.textContent = nome;
    sel.appendChild(opt);

    _newArmModal.hide();
  } catch(e) {
    err.textContent = 'Erro de ligação.'; err.style.display = '';
  } finally {
    btn.disabled = false; btn.textContent = 'Criar Armário';
  }
}

// ── Export ────────────────────────────────────────────────────
function doExport() {
  const url = `api/inventario.php?action=export${currentArm ? '&armario='+encodeURIComponent(currentArm) : ''}`;
  window.location = url;
}

// ── Import ────────────────────────────────────────────────────
let _invFile = null;
function setImportFile(file) {
  _invFile = file;
  const lbl = document.getElementById('inv-drop-zone').querySelector('div:nth-child(2)');
  lbl.textContent = '📄 ' + file.name;
  document.getElementById('inv-btn-do-import').disabled = false;
}

function resetImportBtn() {
  const btn = document.getElementById('inv-btn-do-import');
  if (btn) { btn.disabled = false; btn.textContent = '📥 Importar'; }
}

async function doImport() {
  if (!_invFile) return;
  const btn = document.getElementById('inv-btn-do-import');
  btn.disabled = true; btn.textContent = '⏳ A analisar...';
  const fd = new FormData();
  fd.append('file', _invFile);
  const r = await fetch('api/inventario.php?action=import&dry_run=1', {method:'POST', body:fd});
  const d = await r.json();
  const res = document.getElementById('inv-import-result');
  if (d.error) {
    res.style.display = 'block';
    res.innerHTML = `<span class="text-danger">❌ ${d.error}</span>`;
    resetImportBtn(); return;
  }
  res.style.display = 'none';
  if (!d.changes || d.changes.length === 0) {
    res.style.display = 'block';
    res.innerHTML = `<span class="text-info">ℹ️ Sem alterações. ${d.unchanged||0} itens já actualizados.</span>`;
    resetImportBtn(); return;
  }
  // Fechar modal de import e abrir review
  _importModal.hide();
  startReview(d.changes, d.unchanged || 0);
}

// ── Review one-by-one ─────────────────────────────────────────
let _revChanges = [], _revIdx = 0, _revAccepted = [], _revUnchanged = 0;

function startReview(changes, unchanged) {
  _revChanges  = changes;
  _revIdx      = 0;
  _revAccepted = [];
  _revUnchanged = unchanged;
  showRevItem();
  _reviewModal.show();
}

function showRevItem() {
  const ch   = _revChanges[_revIdx];
  const prog = document.getElementById('inv-rev-progress');
  const acc  = document.getElementById('inv-rev-accepted-count');
  const card = document.getElementById('inv-rev-card');
  const bar  = document.getElementById('inv-rev-bar');
  prog.textContent = `Alteração ${_revIdx + 1} de ${_revChanges.length}`;
  acc.textContent  = `${_revAccepted.length} aceite${_revAccepted.length !== 1 ? 's' : ''}`;
  bar.style.width  = `${(_revIdx / _revChanges.length) * 100}%`;

  const isNew = ch.type === 'new';
  let html = `<div class="mb-2"><span class="badge ${isNew ? 'bg-success' : 'bg-warning text-dark'}">${isNew ? '➕ Novo' : '✏️ Atualizado'}</span></div>`;
  html += `<div class="fw-semibold">${ch.armario} — ${esc(ch.descricao)}</div>`;
  if (isNew) {
    html += `<div class="mt-2 small">Quantidade: <strong>${esc(ch.quantidade||'—')}</strong></div>`;
  } else {
    const qA = ch.qty_antes || '—', qD = ch.quantidade || '—';
    html += `<div class="mt-2 small">Quantidade: <span class="text-danger text-decoration-line-through">${esc(qA)}</span> → <span class="text-success fw-bold">${esc(qD)}</span></div>`;
  }
  if (ch.prateleira) html += `<div class="small text-muted">Prateleira: ${esc(ch.prateleira)}</div>`;
  if (ch.projeto)    html += `<div class="small text-muted">Projeto: ${esc(ch.projeto)}</div>`;
  card.innerHTML = html;
}

function advanceReview(accept) {
  if (accept) _revAccepted.push(_revChanges[_revIdx]);
  _revIdx++;
  if (_revIdx >= _revChanges.length) { finishReview(); return; }
  showRevItem();
}

async function finishReview() {
  _reviewModal.hide();
  const res = document.getElementById('inv-import-result');
  res.style.display = 'block';
  if (_revAccepted.length === 0) {
    res.innerHTML = `<span class="text-info">ℹ️ Nenhuma alteração aceite. ${_revUnchanged} itens sem mudanças.</span>`;
    resetImportBtn(); return;
  }
  resetImportBtn();
  const btn = document.getElementById('inv-btn-do-import');
  btn.disabled = true; btn.textContent = '⏳ A guardar...';
  try {
    const r = await fetch('api/inventario.php?action=commit_import', {
      method: 'POST',
      headers: {'Content-Type': 'application/json'},
      body: JSON.stringify(_revAccepted)
    });
    const d = await r.json();
    if (d.error) {
      res.innerHTML = `<span class="text-danger">❌ ${d.error}</span>`;
    } else {
      res.innerHTML = `<span class="text-success">✅ ${d.imported} novos, ${d.updated} actualizados. ${_revChanges.length - _revAccepted.length} saltados. ${_revUnchanged} sem mudanças.</span>`;
      loadItems();
    }
  } catch(e) {
    res.innerHTML = `<span class="text-danger">❌ Erro de rede</span>`;
  }
  resetImportBtn();
}

})();
</script>
