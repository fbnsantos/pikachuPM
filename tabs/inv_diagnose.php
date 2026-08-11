<?php
// Diagnóstico do import de inventário — APAGAR após uso
session_start();
if (!isset($_SESSION['username'])) { header('Location: ../login.php'); exit; }
?><!DOCTYPE html>
<html><head><meta charset="utf-8"><title>inv diagnose</title>
<style>
body{font-family:monospace;padding:20px;background:#1a1a1a;color:#eee}
h2{color:#7dd3fc}
.ok{color:#4ade80}.err{color:#f87171}.warn{color:#fbbf24}
pre{background:#111;padding:12px;border-radius:6px;overflow:auto}
.step{border-left:3px solid #4ade80;padding:6px 12px;margin:8px 0;background:#111}
.step.fail{border-color:#f87171}
form{margin:20px 0;padding:16px;background:#222;border-radius:8px}
input[type=file]{color:#eee}
input[type=submit]{background:#0d6efd;color:#fff;border:none;padding:8px 20px;border-radius:6px;cursor:pointer}
</style></head><body>
<h2>Diagnóstico Import XLSX</h2>

<?php
function ok(string $s)   { echo "<div class='step'><span class='ok'>✓</span> $s</div>"; }
function err(string $s)  { echo "<div class='step fail'><span class='err'>✗</span> $s</div>"; }
function warn(string $s) { echo "<div class='step'><span class='warn'>⚠</span> $s</div>"; }

// ── 1. Extensões PHP ─────────────────────────────────────────
echo "<h2>1. Extensões PHP</h2>";
extension_loaded('zip')       ? ok("ZipArchive disponível")     : err("ZipArchive NÃO disponível");
extension_loaded('xmlreader') ? ok("XMLReader disponível")       : err("XMLReader NÃO disponível");
class_exists('ZipArchive')    ? ok("ZipArchive instanciável")    : err("ZipArchive não instanciável");

// ── 2. Upload config ─────────────────────────────────────────
echo "<h2>2. Configuração de Upload</h2>";
ok("upload_max_filesize = " . ini_get('upload_max_filesize'));
ok("post_max_size = " . ini_get('post_max_size'));
ok("max_execution_time = " . ini_get('max_execution_time') . "s");
ok("memory_limit = " . ini_get('memory_limit'));

// ── 3. Temp dir ──────────────────────────────────────────────
echo "<h2>3. Directório temporário</h2>";
$td = sys_get_temp_dir();
ok("sys_get_temp_dir = $td");
is_writable($td) ? ok("Directório temp é writable") : err("Directório temp NÃO é writable: $td");

// ── 4. XMLReader::XML() test ─────────────────────────────────
echo "<h2>4. XMLReader::XML() directo</h2>";
$testXml = '<?xml version="1.0"?><root><item>hello</item><item>world</item></root>';
$xr = new XMLReader();
if ($xr->XML($testXml)) {
    $vals = [];
    while ($xr->read()) {
        if ($xr->nodeType === XMLReader::TEXT) $vals[] = $xr->value;
    }
    $xr->close();
    count($vals) === 2 && $vals[0] === 'hello'
        ? ok("XMLReader::XML() funciona — leu: " . implode(', ', $vals))
        : err("XMLReader::XML() leu mas valores errados: " . implode(', ', $vals));
} else {
    err("XMLReader::XML() retornou false");
}

// ── 5. ZipArchive test ───────────────────────────────────────
echo "<h2>5. ZipArchive test</h2>";
$tmpZip = tempnam(sys_get_temp_dir(), 'inv_diag_');
$z = new ZipArchive();
if ($z->open($tmpZip, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true) {
    $z->addFromString('test.txt', 'hello zip');
    $z->close();
    $z2 = new ZipArchive();
    if ($z2->open($tmpZip) === true) {
        $content = $z2->getFromName('test.txt');
        $z2->close();
        $content === 'hello zip' ? ok("ZipArchive create+read funciona") : err("ZipArchive leu conteúdo errado: $content");
    } else { err("ZipArchive não conseguiu re-abrir ficheiro temp"); }
    unlink($tmpZip);
} else { err("ZipArchive não conseguiu criar ficheiro temp em: $td"); }

// ── 6. Testar ficheiro xlsx real ─────────────────────────────
echo "<h2>6. Teste com ficheiro xlsx</h2>";

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_FILES['xlsx']['tmp_name'])) {
    $fp = $_FILES['xlsx']['tmp_name'];
    $sz = $_FILES['xlsx']['size'];
    $er = $_FILES['xlsx']['error'];
    ok("Ficheiro recebido: size={$sz}B error=$er");

    if ($er !== UPLOAD_ERR_OK) {
        $errs = [1=>'MAX_FILE_SIZE',2=>'MAX_FILE_SIZE_form',3=>'PARTIAL',4=>'NO_FILE',6=>'NO_TMP_DIR',7=>'CANT_WRITE',8=>'EXTENSION'];
        err("Erro de upload: " . ($errs[$er] ?? $er));
    } else {
        $zip = new ZipArchive();
        $opened = $zip->open($fp);
        if ($opened !== true) {
            err("ZipArchive::open() falhou com código: $opened");
        } else {
            ok("ZipArchive abriu — {$zip->numFiles} ficheiros no zip");
            echo "<pre>";
            for ($i = 0; $i < $zip->numFiles; $i++)
                echo htmlspecialchars($zip->getNameIndex($i)) . "\n";
            echo "</pre>";

            $ssRaw = $zip->getFromName('xl/sharedStrings.xml') ?: '';
            ok("sharedStrings.xml len=" . strlen($ssRaw) . " bytes");

            if ($ssRaw) {
                // parse com XMLReader::XML()
                $xr = new XMLReader();
                $ok = $xr->XML($ssRaw);
                unset($ssRaw);
                ok("XMLReader::XML(sharedStrings) opened=" . ($ok ? 'true' : 'false'));
                $cnt = 0; $t0 = microtime(true);
                while ($xr->read()) {
                    if ($xr->nodeType === XMLReader::END_ELEMENT && $xr->localName === 'si') $cnt++;
                    if ($cnt > 0 && microtime(true) - $t0 > 10) { warn("XMLReader demorou >10s a ler strings — abortado aos $cnt strings"); break; }
                }
                $xr->close();
                ok("sharedStrings: $cnt strings lidas em " . round(microtime(true)-$t0,2) . "s");
            }

            // workbook sheet map
            $wbRaw  = $zip->getFromName('xl/workbook.xml') ?? '';
            $wbRRaw = $zip->getFromName('xl/_rels/workbook.xml.rels') ?? '';
            ok("workbook.xml len=" . strlen($wbRaw) . " wbRels len=" . strlen($wbRRaw));

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
            ok("Folhas encontradas: " . implode(', ', array_keys($sheetFile)));
            echo "<pre>" . htmlspecialchars(json_encode($sheetFile, JSON_PRETTY_PRINT)) . "</pre>";

            // testar leitura de cada folha A1-A8
            $armarios = ['A1','A2','A3','A4','A5','A6','A7','A8'];
            foreach ($armarios as $arm) {
                if (!isset($sheetFile[$arm])) { warn("Folha '$arm' não existe no ficheiro"); continue; }
                $wsRaw = $zip->getFromName($sheetFile[$arm]);
                if (!$wsRaw) { err("getFromName('{$sheetFile[$arm]}') falhou para $arm"); continue; }
                ok("$arm: worksheet len=" . strlen($wsRaw) . " bytes");

                // conta linhas com XMLReader::XML()
                $xr2 = new XMLReader();
                $xr2->XML($wsRaw);
                unset($wsRaw);
                $rowCount = 0; $t1 = microtime(true);
                while ($xr2->read()) {
                    if ($xr2->nodeType === XMLReader::END_ELEMENT && $xr2->localName === 'row') $rowCount++;
                }
                $xr2->close();
                ok("$arm: $rowCount linhas em " . round(microtime(true)-$t1,3) . "s");
            }
            $zip->close();

            // ── 7. Teste BD ────────────────────────────────
            echo "<h2>7. Teste base de dados</h2>";
            include_once __DIR__ . '/../config.php';
            try {
                $pdo = new PDO("mysql:host=$db_host;dbname=$db_name;charset=utf8mb4", $db_user, $db_pass,
                    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
                ok("PDO ligado a $db_host / $db_name");

                $t2 = microtime(true);
                $cnt2 = (int)$pdo->query("SELECT COUNT(*) FROM inventario_items")->fetchColumn();
                ok("inventario_items tem $cnt2 registos — query em " . round(microtime(true)-$t2,3) . "s");

                // simula INSERT + UPDATE com transaction
                $t3 = microtime(true);
                $pdo->beginTransaction();
                $stIns = $pdo->prepare('INSERT INTO inventario_items (armario,descricao,quantidade,created_by) VALUES (?,?,?,?)');
                $stDel = $pdo->prepare('DELETE FROM inventario_items WHERE id=?');
                $stIns->execute(['A1','__inv_diag_test__','0',0]);
                $testId = (int)$pdo->lastInsertId();
                $stDel->execute([$testId]);
                $pdo->commit();
                ok("INSERT+DELETE de teste em " . round(microtime(true)-$t3,3) . "s — id foi $testId");

                // simula 100 inserts em transação
                $t4 = microtime(true);
                $ids = [];
                $pdo->beginTransaction();
                $stIns2 = $pdo->prepare('INSERT INTO inventario_items (armario,descricao,quantidade,created_by) VALUES (?,?,?,?)');
                $stDel2 = $pdo->prepare('DELETE FROM inventario_items WHERE id=?');
                for ($i = 0; $i < 100; $i++) {
                    $stIns2->execute(['A1',"__diag_bulk_$i",'0',0]);
                    $ids[] = (int)$pdo->lastInsertId();
                }
                $pdo->commit();
                $elapsed4 = round(microtime(true)-$t4,3);
                ok("100 INSERTs em transação: {$elapsed4}s → estimativa 6300 rows: " . round($elapsed4/100*6300,1) . "s");

                // limpeza
                $pdo->beginTransaction();
                foreach ($ids as $id) $stDel2->execute([$id]);
                $pdo->commit();
                ok("Limpeza feita");

                // ── 8. Import real ──────────────────────────
                if (!empty($_POST['do_import'])) {
                    echo "<h2>8. Import real</h2>";
                    $armarios = ['A1','A2','A3','A4','A5','A6','A7','A8'];
                    $uid = (int)($_SESSION['user_id'] ?? 0);

                    // re-abrir zip (foi fechado acima)
                    $zip2 = new ZipArchive();
                    $zip2->open($_FILES['xlsx']['tmp_name']);
                    $ssRaw2 = $zip2->getFromName('xl/sharedStrings.xml') ?: '';
                    $strings2 = [];
                    if ($ssRaw2) {
                        $xrS = new XMLReader(); $xrS->XML($ssRaw2); unset($ssRaw2);
                        $cur = ''; $inT = false;
                        while ($xrS->read()) {
                            if ($xrS->nodeType===XMLReader::ELEMENT) {
                                if ($xrS->localName==='si') $cur='';
                                elseif ($xrS->localName==='t') $inT=true;
                            } elseif ($xrS->nodeType===XMLReader::TEXT && $inT) { $cur.=$xrS->value; }
                            elseif ($xrS->nodeType===XMLReader::END_ELEMENT) {
                                if ($xrS->localName==='si') { $strings2[]=$cur; $cur=''; }
                                elseif ($xrS->localName==='t') $inT=false;
                            }
                        }
                        $xrS->close();
                    }
                    ok(count($strings2) . " shared strings carregadas");

                    $wbR2  = $zip2->getFromName('xl/workbook.xml') ?? '';
                    $wbRR2 = $zip2->getFromName('xl/_rels/workbook.xml.rels') ?? '';
                    $rId2=[]; preg_match_all('/Id="([^"]+)"[^>]+Target="([^"]+)"/',$wbRR2,$rm2,PREG_SET_ORDER);
                    foreach($rm2 as $m) $rId2[$m[1]]=$m[2];
                    $sf2=[]; preg_match_all('/<sheet\b[^>]+>/i',$wbR2,$sm2);
                    foreach($sm2[0] as $tag) {
                        if(!preg_match('/name="([^"]+)"/',$tag,$nm)) continue;
                        if(!preg_match('/r:id="([^"]+)"/',$tag,$ri)) continue;
                        $f=$rId2[$ri[1]]??'';
                        if($f) $sf2[$nm[1]]=(strpos($f,'/')===0)?ltrim($f,'/') : 'xl/'.$f;
                    }

                    $existing2=[];
                    foreach($pdo->query("SELECT id,armario,descricao FROM inventario_items")->fetchAll(PDO::FETCH_ASSOC) as $r)
                        $existing2[$r['armario'].'||'.$r['descricao']]=(int)$r['id'];

                    $stUpd = $pdo->prepare('UPDATE inventario_items SET quantidade=?,prateleira=?,caixa=?,projeto=?,last_edited=?,updated_at=NOW() WHERE id=?');
                    $stIns3= $pdo->prepare('INSERT INTO inventario_items (armario,descricao,quantidade,prateleira,caixa,projeto,last_edited,created_by) VALUES (?,?,?,?,?,?,?,?)');
                    $imported2=$updated2=$skipped2=0;

                    $pdo->beginTransaction();
                    try {
                        foreach($armarios as $arm) {
                            if(!isset($sf2[$arm])) { warn("Folha '$arm' não encontrada"); continue; }
                            $wsR=$zip2->getFromName($sf2[$arm]);
                            if(!$wsR) { warn("Erro ao ler '$arm'"); continue; }
                            // parse worksheet
                            $rows2=[]; $cells2=[]; $rn=0; $cc=0; $ct=''; $cv=''; $iv=false;
                            $xrW=new XMLReader(); $xrW->XML($wsR); unset($wsR);
                            while($xrW->read()) {
                                if($xrW->nodeType===XMLReader::ELEMENT) {
                                    $ln=$xrW->localName;
                                    if($ln==='row'){$cells2=[];$rn++;}
                                    elseif($ln==='c'){
                                        $ref=$xrW->getAttribute('r')??''; preg_match('/^([A-Z]+)/',$ref,$cm3);
                                        $col=$cm3[1]??'A'; $cc=0;
                                        for($i=0;$i<strlen($col);$i++) $cc=$cc*26+(ord($col[$i])-64);
                                        $cc--; $ct=$xrW->getAttribute('t')??''; $cv=''; $iv=false;
                                    } elseif($ln==='v'){$iv=true;$cv='';}
                                } elseif(($xrW->nodeType===XMLReader::TEXT||$xrW->nodeType===XMLReader::CDATA)&&$iv){
                                    $cv.=$xrW->value;
                                } elseif($xrW->nodeType===XMLReader::END_ELEMENT) {
                                    $ln=$xrW->localName;
                                    if($ln==='v'){$iv=false;$v=$cv;if($ct==='s')$v=$strings2[(int)$v]??'';$cells2[$cc]=$v;}
                                    elseif($ln==='row'){if($rn>1)$rows2[]=$cells2;}
                                }
                            }
                            $xrW->close();
                            foreach($rows2 as $cells2r) {
                                $desc=trim($cells2r[1]??''); if(!$desc){$skipped2++;continue;}
                                $qty=trim($cells2r[2]??''); $prat=trim($cells2r[3]??'');
                                $cx=trim($cells2r[4]??''); $proj=trim($cells2r[5]??'');
                                $led=null; $lr=$cells2r[6]??'';
                                if($lr!==''&&is_numeric($lr)&&(float)$lr>40000)
                                    $led=date('Y-m-d',(int)(((float)$lr-25569)*86400));
                                $key=$arm.'||'.$desc;
                                if(isset($existing2[$key])){$stUpd->execute([$qty,$prat,$cx,$proj,$led,$existing2[$key]]);$updated2++;}
                                else{$stIns3->execute([$arm,$desc,$qty,$prat,$cx,$proj,$led,$uid]);$existing2[$key]=(int)$pdo->lastInsertId();$imported2++;}
                            }
                            ok("$arm: " . count($rows2) . " linhas processadas");
                        }
                        $pdo->commit();
                        echo "<div class='step' style='border-color:#7dd3fc;font-size:16px'><b style='color:#7dd3fc'>Import concluído!</b> Importados: $imported2 &nbsp; Actualizados: $updated2 &nbsp; Ignorados: $skipped2</div>";
                    } catch(Exception $e) {
                        $pdo->rollBack();
                        err("Erro no import: " . $e->getMessage());
                    }
                    $zip2->close();
                }

            } catch (Exception $e) {
                err("Erro BD: " . $e->getMessage());
            }
        }
    }
} else {
    echo "<form method='post' enctype='multipart/form-data'>
        <label>Selecciona o ficheiro xlsx:</label><br><br>
        <input type='file' name='xlsx' accept='.xlsx'><br><br>
        <label style='display:flex;align-items:center;gap:8px;margin-bottom:12px;cursor:pointer'>
            <input type='checkbox' name='do_import' value='1'>
            <span>Fazer import real para a base de dados</span>
        </label>
        <input type='submit' value='Testar / Importar'>
    </form>";
}

echo "<p style='color:#6c757d;margin-top:30px;font-size:11px'>Apaga este ficheiro depois de usar.</p>";
?>
</body></html>
