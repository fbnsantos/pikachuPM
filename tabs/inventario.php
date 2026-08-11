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

$cur_uid  = (int)($_SESSION['user_id'] ?? 0);
$ARMARIOS = ['A1','A2','A3','A4','A5','A6','A7','A8'];
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
$armCounts = [];
foreach ($ARMARIOS as $arm)
    $armCounts[$arm] = (int)$pdo->query("SELECT COUNT(*) FROM inventario_items WHERE armario='$arm'")->fetchColumn();
$totalItems = array_sum($armCounts);
?>

<style>
.inv-wrap{display:flex;flex-direction:column;gap:0;height:calc(100vh - 120px);min-height:400px}
.inv-toolbar{display:flex;align-items:center;gap:8px;padding:10px 0;flex-wrap:wrap;flex-shrink:0}
.inv-toolbar-right{display:flex;align-items:center;gap:6px;margin-left:auto}
.inv-search{flex:1;min-width:180px;max-width:320px;position:relative}
.inv-search input{width:100%;padding:7px 10px 7px 34px;border:1px solid #dee2e6;border-radius:8px;font-size:13px;outline:none;transition:border-color .15s}
.inv-search input:focus{border-color:#0d6efd}
.inv-search .inv-s-icon{position:absolute;left:10px;top:50%;transform:translateY(-50%);color:#6c757d;font-size:14px}

.inv-armtabs{display:flex;gap:4px;flex-wrap:wrap;flex-shrink:0;padding-bottom:8px;border-bottom:2px solid #dee2e6;margin-bottom:0}
.inv-atab{padding:5px 14px;border:1px solid #dee2e6;border-radius:20px;font-size:12px;font-weight:600;cursor:pointer;background:#fff;color:#495057;transition:all .15s;display:flex;align-items:center;gap:5px}
.inv-atab:hover{border-color:#0d6efd;color:#0d6efd}
.inv-atab.active{background:#0d6efd;border-color:#0d6efd;color:#fff}
.inv-atab .inv-cnt{background:rgba(0,0,0,.12);border-radius:10px;padding:1px 6px;font-size:10px}
.inv-atab.active .inv-cnt{background:rgba(255,255,255,.25)}

.inv-table-wrap{flex:1;overflow:auto;border:1px solid #dee2e6;border-radius:10px;margin-top:10px}
.inv-table-wrap table{width:100%;border-collapse:collapse;font-size:13px}
.inv-table-wrap thead th{background:#f8f9fa;padding:9px 12px;text-align:left;font-weight:600;font-size:11px;text-transform:uppercase;letter-spacing:.4px;color:#6c757d;border-bottom:2px solid #dee2e6;white-space:nowrap;position:sticky;top:0;z-index:2}
.inv-table-wrap tbody td{padding:8px 12px;border-bottom:1px solid #f0f0f0;vertical-align:middle}
.inv-table-wrap tbody tr:last-child td{border-bottom:none}
.inv-table-wrap tbody tr:hover{background:#f8f9fa}
.inv-desc{font-weight:500;color:#212529;max-width:300px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.inv-arm-badge{display:inline-block;padding:2px 8px;border-radius:12px;font-size:10px;font-weight:700;background:#e8f0fe;color:#1a56db}
.inv-qty{font-weight:700;color:#212529}
.inv-meta{color:#6c757d;font-size:12px}
.inv-actions{display:flex;gap:4px;opacity:0;transition:opacity .15s}
tr:hover .inv-actions{opacity:1}
.inv-empty{text-align:center;padding:60px 20px;color:#6c757d}
.inv-empty-icon{font-size:48px;opacity:.35;margin-bottom:8px}
.inv-loading{text-align:center;padding:40px;color:#6c757d}

/* Modal */
.inv-modal .modal-dialog{max-width:600px}
.inv-modal .form-label{font-size:12px;font-weight:600;color:#6c757d;text-transform:uppercase;letter-spacing:.4px}
.inv-modal .form-control{font-size:13px}
.inv-form-row{display:grid;grid-template-columns:1fr 1fr;gap:12px}

/* Import modal */
.inv-drop{border:2px dashed #dee2e6;border-radius:10px;padding:32px;text-align:center;cursor:pointer;transition:all .2s;background:#fafafa}
.inv-drop:hover,.inv-drop.over{border-color:#0d6efd;background:#f0f4ff}
.inv-drop-icon{font-size:40px;margin-bottom:8px;opacity:.5}
.inv-import-result{background:#f8f9fa;border-radius:8px;padding:12px;font-size:13px;margin-top:12px;display:none}

@media(max-width:600px){.inv-form-row{grid-template-columns:1fr}.inv-toolbar-right{width:100%}}
</style>

<!-- Toolbar -->
<div class="inv-wrap">
  <div class="inv-toolbar">
    <div class="inv-search">
      <span class="inv-s-icon">🔍</span>
      <input type="text" id="inv-search-inp" placeholder="Pesquisar itens...">
    </div>
    <div class="inv-toolbar-right">
      <button class="btn btn-sm btn-outline-secondary" id="inv-btn-import" title="Importar XLSX">
        📥 Importar
      </button>
      <button class="btn btn-sm btn-outline-secondary" id="inv-btn-export" title="Exportar XLSX">
        📤 Exportar
      </button>
      <button class="btn btn-sm btn-primary" id="inv-btn-add">
        + Adicionar Item
      </button>
    </div>
  </div>

  <!-- Armário tabs -->
  <div class="inv-armtabs">
    <button class="inv-atab active" data-arm="">
      Todos <span class="inv-cnt"><?= $totalItems ?></span>
    </button>
    <?php foreach ($ARMARIOS as $arm): ?>
    <button class="inv-atab" data-arm="<?= $arm ?>">
      <?= $arm ?> <span class="inv-cnt"><?= $armCounts[$arm] ?></span>
    </button>
    <?php endforeach; ?>
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

<script>
(function(){
'use strict';

let currentArm = '';
let allItems   = [];
let searchQ    = '';
let _modal, _importModal;

// ── Init ─────────────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', () => {
  _modal       = new bootstrap.Modal(document.getElementById('invModal'));
  _importModal = new bootstrap.Modal(document.getElementById('invImportModal'));

  // Armário tabs
  document.querySelectorAll('.inv-atab').forEach(btn => {
    btn.addEventListener('click', () => {
      document.querySelectorAll('.inv-atab').forEach(b => b.classList.remove('active'));
      btn.classList.add('active');
      currentArm = btn.dataset.arm;
      loadItems();
    });
  });

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
      <td class="inv-qty">${it.quantidade||'—'}</td>
      <td class="inv-meta">${it.prateleira||'—'}</td>
      <td class="inv-meta">${it.caixa||'—'}</td>
      <td class="inv-meta">${it.projeto?`<span style="background:#e8f4ea;color:#155724;border-radius:10px;padding:1px 8px;font-size:11px;font-weight:600">${esc(it.projeto)}</span>`:'—'}</td>
      <td class="inv-meta">${it.last_edited||'—'}</td>
      <td>
        <div class="inv-actions">
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

function updateCount() {
  const tot = allItems.length;
  document.querySelector('.inv-atab[data-arm=""]>.inv-cnt').textContent = tot;
}

// ── Export ────────────────────────────────────────────────────
function doExport() {
  const url = `?action=export${currentArm ? '&armario='+currentArm : ''}`;
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

async function doImport() {
  if (!_invFile) return;
  const btn = document.getElementById('inv-btn-do-import');
  btn.disabled = true; btn.textContent = '⏳ A importar...';
  const fd = new FormData();
  fd.append('file', _invFile);
  const r = await fetch('api/inventario.php?action=import', {method:'POST', body:fd});
  const d = await r.json();
  const res = document.getElementById('inv-import-result');
  res.style.display = 'block';
  if (d.error) {
    res.innerHTML = `<span class="text-danger">❌ ${d.error}</span>`;
  } else {
    res.innerHTML = `<span class="text-success">✅ ${d.imported} novos importados, ${d.updated} actualizados, ${d.skipped} ignorados.</span>`
      + (d.errors?.length ? `<br><span class="text-warning">⚠️ ${d.errors.join('; ')}</span>` : '');
    loadItems();
  }
  btn.disabled = false; btn.textContent = '📥 Importar';
}

})();
</script>
