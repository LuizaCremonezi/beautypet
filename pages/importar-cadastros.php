<?php
/** Importador legado Beauty Pet — revisão obrigatória antes de gravar. */
require __DIR__ . '/../config/config.php';
iniciarSessao();
$operador = obterUsuarioAtual($pdo);
if (!$operador || $operador['perfil'] !== 'ADMINISTRADOR') {
    http_response_code(403);
    exit('Acesso restrito a administradores autenticados.');
}
if (empty($_SESSION['bp_import_csrf'])) $_SESSION['bp_import_csrf'] = bin2hex(random_bytes(24));
$csrf = $_SESSION['bp_import_csrf'];
$erros = []; $alertas = []; $resumo = []; $linhas = []; $gravado = null;
$tipo = $_POST['acao'] ?? '';
function bpNorm(?string $v): string {
    $v = trim((string)$v);
    $v = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $v) ?: $v;
    return preg_replace('/\s+/', ' ', mb_strtoupper($v, 'UTF-8'));
}
function bpBreedKey(?string $v): string {
    // Identidade da raça: maiúsculas/minúsculas, acentos e espaços não criam raças novas.
    return bpNorm((string)$v);
}
function bpBreedName(string $value): string {
    $value=preg_replace('/\s+/u', ' ', trim($value));
    // Capitalização amigável para novas raças sem alterar as raças já existentes.
    return mb_convert_case(mb_strtolower($value, 'UTF-8'), MB_CASE_TITLE, 'UTF-8');
}
function bpDigs(?string $v): string {return preg_replace('/\D+/', '', (string)$v);}
function bpField(array $a, string $key): string {return trim((string)($a[$key] ?? ''));}
function bpGetIndex(array $head,string $name): int {
    $idx=array_search($name,$head,true);if($idx===false)throw new RuntimeException('Coluna obrigatória não encontrada: '.$name);return $idx;
}
function bpReadCsv(string $file): array {
    $fp=fopen($file,'rb');if(!$fp)throw new RuntimeException('Não foi possível abrir arquivo.');
    try {
        $firstLine=fgets($fp);
        if ($firstLine===false) throw new RuntimeException('CSV vazio.');
        $delimiter=substr_count($firstLine,';') >= substr_count($firstLine,',') ? ';' : ',';
        rewind($fp);
        $header=fgetcsv($fp,0,$delimiter,'"','');
        if (!$header) throw new RuntimeException('CSV vazio.');
        $header=array_map(fn($v)=>trim(preg_replace('/^\xEF\xBB\xBF/','',$v)), $header);
        $names=['linha_excel','codigo_antigo','cliente_nome','cpf','endereco','bairro','cep','cidade','telefone_fixo','telefone_celular','sexo_pet','especie_pet','pet_nome','raca_pet','email'];
        // O relatório de conferência não contém todos os campos para importação.
        if(in_array('cliente',$header,true) && in_array('status',$header,true) && !in_array('cliente_nome',$header,true)) {
            throw new RuntimeException('Você selecionou o relatório de importação. Envie o arquivo cadastros_beautypet_para_importacao.csv (a planilha preparada), não relatorio-importacao-beautypet.csv.');
        }
        $missing=array_values(array_diff($names,$header));
        if($missing) throw new RuntimeException('Este CSV não está no formato de importação. Colunas ausentes: '.implode(', ',$missing).'. Utilize cadastros_beautypet_para_importacao.csv.');
        $out=[];
        while(($cells=fgetcsv($fp,0,$delimiter,'"',''))!==false){
            if(count($out)>=10000)throw new RuntimeException('Limite de 10.000 linhas excedido.');
            $cells=array_pad($cells,count($header),'');
            $record=array_combine($header,array_slice($cells,0,count($header)));
            if(!trim(implode('', $cells)))continue;
            $out[]=$record;
        }
        return $out;
    } finally {fclose($fp);}
}
function bpNormalizeSpecies(string $value): ?string {
    $v=preg_replace('/[^A-Z]/','',bpNorm($value));
    if(in_array($v,['CAO','CA','CAP','CACHORRO','CANINO'],true))return 'CAO';
    if(in_array($v,['GATO','GATA','FELINO'],true))return 'GATO';
    if(in_array($v,['COELHO','CELHO'],true))return 'COELHO';
    if(in_array($v,['PORQUINHO','PORQUINHODAINDIA','COBAIA'],true))return 'PORQUINHO-DA-INDIA';
    if($v==='GALO')return 'GALO';
    return null;
}
function bpValidate(PDO $pdo,array $rows): array {
    $species=[]; foreach($pdo->query('SELECT id,nome FROM especies WHERE ativo = TRUE') as $s) {
        $canonical=bpNormalizeSpecies($s['nome']) ?? bpNorm($s['nome']);
        $species[$canonical]=(int)$s['id'];
    }
    $breeds=[];
    foreach($pdo->query('SELECT id,especie_id,nome FROM racas ORDER BY id') as $r) {
        $breedKey=$r['especie_id'].'|'.bpBreedKey($r['nome']);
        if(!isset($breeds[$breedKey])) $breeds[$breedKey]=(int)$r['id'];
    }
    $existingCpf=[];$existingCpfNames=[];$existingNamePhone=[];
    foreach($pdo->query('SELECT id,nome,cpf,telefone_principal,telefone_reserva FROM clientes') as $c){
        $cpf=bpDigs($c['cpf']);if(strlen($cpf)===11){$existingCpf[$cpf][]=(int)$c['id'];$existingCpfNames[$cpf][(int)$c['id']]=bpNorm($c['nome']);}
        foreach([$c['telefone_principal'],$c['telefone_reserva']] as $phone){
            $phone=bpDigs($phone);if(strlen($phone)>=8)$existingNamePhone[bpNorm($c['nome']).'|'.$phone][]=(int)$c['id'];
        }
    }
    $seenCpf=[];$seenNamePhone=[];$seenPet=[];$out=[];$counts=['total'=>count($rows),'aprovados'=>0,'revisao'=>0,'bloqueados'=>0];
    foreach($rows as $r){
        $issues=[];$warnings=[];$name=bpField($r,'cliente_nome');$pet=bpField($r,'pet_nome');
        $cpf=bpDigs(bpField($r,'cpf'));$fixo=bpDigs(bpField($r,'telefone_fixo'));$cel=bpDigs(bpField($r,'telefone_celular'));
        $phone=strlen($cel)>=8?$cel:(strlen($fixo)>=8?$fixo:'');
        $sp=bpNormalizeSpecies(bpField($r,'especie_pet'));
        $spid=$sp==null?null:($species[$sp]??null);
        $raca=bpBreedKey(bpField($r,'raca_pet'));$breedid=$spid&&$raca!==''?($breeds[$spid.'|'.$raca]??null):null;
        if(!$name)$issues[]='Nome do tutor ausente';
        if(!$pet)$warnings[]='Pet sem nome: será importado somente o tutor';
        if(!$phone)$warnings[]='Telefone não informado: será salvo vazio (não inventado)';
        if($cpf && (strlen($cpf)!==11 || !cpfValido($cpf))){$warnings[]='CPF inválido: será salvo vazio';$cpf='';}
        if($pet && !$sp) $issues[]='Espécie desconhecida: revisar antes de cadastrar o pet';
        if($pet && $sp && !$spid && !in_array($sp,['COELHO','PORQUINHO-DA-INDIA','GALO'],true))$issues[]='Espécie sem correspondência';
        // Espécies conhecidas ainda ausentes serão criadas dentro da transação.
        // Raça nova será criada apenas durante a confirmação, junto com o pet, na mesma transação.
        $sex=bpNorm(bpField($r,'sexo_pet'));
        $sex=in_array($sex,['MACHO','M'],true)?'MACHO':(in_array($sex,['FEMEA','F'],true)?'FEMEA':'NAO_INFORMADO');
        $key=($cpf && strlen($cpf)===11?'CPF:'.$cpf:($phone ? 'NOME:'.bpNorm($name).'|TEL:'.$phone : 'LINHA:'.bpField($r,'linha_excel')));
        if($cpf && isset($existingCpf[$cpf]) && count(array_unique($existingCpf[$cpf]))>1)$issues[]='CPF compartilhado por múltiplos clientes existentes';
        if($cpf && isset($existingCpfNames[$cpf]) && count($existingCpfNames[$cpf])===1 && reset($existingCpfNames[$cpf])!==bpNorm($name))$issues[]='CPF já pertence a cliente do banco com nome diferente — conferir manualmente';
        if($cpf && $name && $phone && isset($existingNamePhone[bpNorm($name).'|'.$phone])){
            $idByCpf=$existingCpf[$cpf]??[];
            foreach($existingNamePhone[bpNorm($name).'|'.$phone] as $nameId){
                if(!in_array($nameId,$idByCpf,true))$issues[]='Nome/telefone já vinculado a um cliente existente diferente do CPF informado';
            }
        }
        if(!$cpf && $name && $phone && count(array_unique($existingNamePhone[bpNorm($name).'|'.$phone]??[]))>1)$issues[]='Vários tutores existentes com mesmo nome e telefone';
        if($cpf && isset($existingCpf[$cpf]) && count(array_unique($existingCpf[$cpf]))===1){
            $existingId=$existingCpf[$cpf][0];
            foreach($existingNamePhone as $lookupKey=>$ids){
                if($lookupKey===bpNorm($name).'|'.$phone && count(array_unique($ids))>1)$issues[]='Nome/telefone com múltiplos tutores no banco';
            }
        }
        if($cpf && isset($seenCpf[$cpf]) && $seenCpf[$cpf]!==bpNorm($name))$issues[]='Mesmo CPF com nomes diferentes no arquivo';
        if($cpf)$seenCpf[$cpf]=bpNorm($name);
        if($phone && $name){
            $np=bpNorm($name).'|'.$phone;
            if(isset($seenNamePhone[$np]) && $seenNamePhone[$np]!==$key) {
                // CPF ausente não é conflito quando nome e telefone coincidem.
                // CPFs explícitos diferentes, porém, exigem revisão.
                if(str_starts_with($seenNamePhone[$np], 'CPF:') && str_starts_with($key, 'CPF:'))
                    $issues[]='Mesmo nome e telefone com CPFs diferentes no arquivo';
            }
            if(!isset($seenNamePhone[$np]) || str_starts_with($key,'CPF:')) $seenNamePhone[$np]=$key;
        }
        $pkey=$key.'|'.bpNorm($pet).'|'.$sp;
        if($pet && isset($seenPet[$pkey]))$issues[]='Possível pet duplicado no Excel (linha '.$seenPet[$pkey].')';
        $seenPet[$pkey]=bpField($r,'linha_excel');
        $state=$issues?'BLOQUEADO':($warnings?'APROVADO':'APROVADO');
        $counts[$state==='BLOQUEADO'?'bloqueados':($state==='REVISAO'?'revisao':'aprovados')]++;
        $out[]=['raca_nome'=>bpField($r,'raca_pet'),'raca_chave'=>$raca,'raw'=>$r,'estado'=>$state,'motivos'=>array_merge($issues,$warnings),'cpf'=>$cpf,'fone'=>$phone,'fone2'=>$phone===$cel?$fixo:$cel,'nome'=>$name,'pet'=>$pet,'especie_id'=>$spid,'especie_nome'=>$sp,'raca_id'=>$breedid,'sexo'=>$sex,'key'=>$key];
    }
    return [$out,$counts];
}
function bpCheckSchema(PDO $pdo, array $items): void {
    $needsUnknownSex = false;
    foreach ($items as $item) {
        if ($item['estado'] === 'APROVADO' && $item['pet'] !== '' && $item['sexo'] === 'NAO_INFORMADO') {
            $needsUnknownSex = true;
            break;
        }
    }
    if (!$needsUnknownSex) return;
    $q = $pdo->prepare("SELECT character_maximum_length FROM information_schema.columns WHERE table_schema = current_schema() AND table_name='pets' AND column_name='sexo'");
    $q->execute();
    $limit = $q->fetchColumn();
    if ($limit !== false && $limit !== null && (int)$limit < strlen('NAO_INFORMADO')) {
        throw new RuntimeException('O campo pets.sexo permite somente '.(int)$limit.' caracteres, mas NAO_INFORMADO necessita 13. No Supabase SQL Editor, execute: ALTER TABLE public.pets ALTER COLUMN sexo TYPE VARCHAR(20); Depois refaça a análise. Nenhum cadastro foi alterado nesta tentativa.');
    }
}
function bpCreate(PDO $pdo,array $items): array {
    // Conferir compatibilidade do campo sexo antes de iniciar a transação.
    bpCheckSchema($pdo, $items);
    // Importar APENAS linhas aprovadas; uma falha desfaz integralmente o lote.
    $pdo->beginTransaction();
    $pdo->exec('SET TRANSACTION ISOLATION LEVEL SERIALIZABLE');
    $pdo->query('SELECT pg_advisory_xact_lock(826310501)');
    $conflictingRows=[];$createdClients=0;$createdPets=0;$skippedPets=0;$createdBreeds=0;$createdSpecies=0;$clientOnly=0;$cache=[];$breedCache=[];$speciesCache=[];
    try {
        $byCpf=$pdo->prepare('SELECT id,nome FROM clientes WHERE regexp_replace(coalesce(cpf,\'\'),\'[^0-9]\',\'\',\'g\') = ? FOR UPDATE');
        $byNamePhone=$pdo->prepare('SELECT id,cpf FROM clientes WHERE upper(trim(nome))=upper(trim(?)) AND (regexp_replace(telefone_principal,\'[^0-9]\',\'\',\'g\')=? OR regexp_replace(coalesce(telefone_reserva,\'\'),\'[^0-9]\',\'\',\'g\')=?) FOR UPDATE');
        $insertC=$pdo->prepare('INSERT INTO clientes(nome,cpf,email,telefone_principal,telefone_reserva,cep,logradouro,bairro,cidade,observacoes) VALUES (?,?,?,?,?,?,?,?,?,?) RETURNING id');
        $petLookup=$pdo->prepare('SELECT id FROM pets WHERE cliente_id=? AND especie_id=? AND upper(trim(nome))=upper(trim(?)) FOR UPDATE');
        $listBreeds=$pdo->prepare('SELECT id,nome FROM racas WHERE especie_id=? ORDER BY id FOR UPDATE');
        $addBreed=$pdo->prepare('INSERT INTO racas (especie_id,nome) VALUES (?,?) RETURNING id');
        $selectSpecies=$pdo->prepare('SELECT id,nome FROM especies ORDER BY id FOR UPDATE');
        $insertSpecies=$pdo->prepare('INSERT INTO especies(nome) VALUES (?) RETURNING id');
        $insertP=$pdo->prepare('INSERT INTO pets(cliente_id,especie_id,raca_id,nome,sexo,observacoes_medicas) VALUES (?,?,?,?,?,?) RETURNING id');
        foreach($items as $i){if($i['estado']!=='APROVADO')continue;
            $r=$i['raw'];$key=$i['key'];
            if(!isset($cache[$key])){
                if($i['cpf']){
                    $byCpf->execute([$i['cpf']]);$matches=$byCpf->fetchAll();
                    $byPhone=[];if($i['fone']!==''){$byNamePhone->execute([$i['nome'],$i['fone'],$i['fone']]);$byPhone=$byNamePhone->fetchAll();}
                    // Se CPF e nome/telefone apontam para IDs diferentes, não vincular
                    // nenhum pet deste registro: encaminhar somente a linha à revisão.
                    $cpfIds=array_map(fn($row)=>(int)$row['id'],$matches);
                    $conflictIds=array_filter($byPhone,fn($candidate)=>!in_array((int)$candidate['id'],$cpfIds,true));
                    if($conflictIds){
                        $conflictingRows[]=['linha'=>bpField($r,'linha_excel'),'nome'=>$i['nome'],'motivo'=>'CPF e nome/telefone apontam para clientes diferentes; revisar manualmente'];
                        continue;
                    }
                }
                else { $matches=[]; if($i['fone']!==''){$byNamePhone->execute([$i['nome'],$i['fone'],$i['fone']]);$matches=$byNamePhone->fetchAll();} }
                if(count($matches)>1){
                    $conflictingRows[]=['linha'=>bpField($r,'linha_excel'),'nome'=>$i['nome'],'motivo'=>'Vários clientes encontrados; revisar manualmente'];
                    continue;
                }
                if($matches && $i['cpf'] && bpNorm($matches[0]['nome'])!==bpNorm($i['nome'])){ $conflictingRows[]=['linha'=>bpField($r,'linha_excel'),'nome'=>$i['nome'],'motivo'=>'CPF associado a outro nome no banco']; continue; }
                // CPF vazio na linha não apaga o CPF do banco.
                // A correspondência sem CPF só ocorre com nome + telefone
                // e apenas quando há um único cliente encontrado.
                // Um CPF já existente no cliente não impede cadastrar outro pet.
                if($matches)$id=(int)$matches[0]['id'];
                else {
                    $insertC->execute([$i['nome'],$i['cpf']?:null,bpField($r,'email')?:null,$i['fone'],$i['fone2']?:null,bpDigs(bpField($r,'cep'))?:null,bpField($r,'endereco')?:null,bpField($r,'bairro')?:null,bpField($r,'cidade')?:null,'Importado do cadastro antigo; código '.bpField($r,'codigo_antigo').', linha '.bpField($r,'linha_excel')]);
                    $id=(int)$insertC->fetchColumn();$createdClients++;
                }
                $cache[$key]=$id;
            }
            $id=$cache[$key];
            if($i['pet']==='') {$clientOnly++;continue;}
            $speciesId=$i['especie_id'];
            if(!$speciesId && $i['especie_nome']!=='') {
                $speciesName=['COELHO'=>'Coelho','PORQUINHO-DA-INDIA'=>'Porquinho-da-índia','GALO'=>'Galo'][$i['especie_nome']] ?? null;
                if(!$speciesName) throw new RuntimeException('Espécie desconhecida na linha '.bpField($r,'linha_excel'));
                if(!isset($speciesCache[$i['especie_nome']])) {
                    $selectSpecies->execute();$speciesId=null;
                    foreach($selectSpecies->fetchAll() as $oldSpecies) if(bpNormalizeSpecies($oldSpecies['nome'])===$i['especie_nome']){$speciesId=(int)$oldSpecies['id'];break;}
                    if(!$speciesId) {$insertSpecies->execute([$speciesName]);$speciesId=(int)$insertSpecies->fetchColumn();$createdSpecies++;}
                    $speciesCache[$i['especie_nome']]=$speciesId;
                } else $speciesId=$speciesCache[$i['especie_nome']];
            }
            $petLookup->execute([$id,$speciesId,$i['pet']]);
            if($petLookup->fetch()){$skippedPets++;continue;}
            $breedId=null;
            if($i['raca_chave']!=='') {
                $breedKey=$speciesId.'|'.$i['raca_chave'];
                if(!array_key_exists($breedKey,$breedCache)) {
                    // Releitura na própria transação: nunca criar duplicatas só por caixa/acentuação.
                    $listBreeds->execute([$speciesId]);
                    $breedCache[$breedKey]=null;
                    foreach($listBreeds->fetchAll() as $existingBreed) {
                        if(bpBreedKey($existingBreed['nome'])===$i['raca_chave']) {
                            $breedCache[$breedKey]=(int)$existingBreed['id'];
                            break;
                        }
                    }
                    if($breedCache[$breedKey]===null) {
                        $addBreed->execute([$speciesId,bpBreedName($i['raca_nome'])]);
                        $breedCache[$breedKey]=(int)$addBreed->fetchColumn();
                        $createdBreeds++;
                    }
                }
                $breedId=$breedCache[$breedKey];
            }
            $insertP->execute([$id,$speciesId,$breedId,$i['pet'],$i['sexo'],'Cadastro antigo — linha '.bpField($r,'linha_excel')]);
            $createdPets++;
        }
        $pdo->commit();return compact('createdClients','createdPets','skippedPets','createdBreeds','createdSpecies','clientOnly','conflictingRows');
    } catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}
if($_SERVER['REQUEST_METHOD']==='POST'){
    if(!hash_equals($csrf,(string)($_POST['csrf']??''))){http_response_code(403);exit('Token de segurança inválido.');}
    try {
        if($tipo==='analisar'){
            if(!isset($_FILES['csv']) || $_FILES['csv']['error']!==UPLOAD_ERR_OK)throw new RuntimeException('Selecione o CSV gerado a partir do Excel.');
            if($_FILES['csv']['size']>10*1024*1024)throw new RuntimeException('CSV excede 10 MB.');
            $rows=bpReadCsv($_FILES['csv']['tmp_name']);
            if(!$rows)throw new RuntimeException('Nenhum registro encontrado.');
            // Guardar dados temporários por sessão, fora da pasta pública.
            $nonce=bin2hex(random_bytes(16));$stage=sys_get_temp_dir().'/bp_import_'.$nonce.'.json';
            file_put_contents($stage,json_encode($rows,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),LOCK_EX);@chmod($stage,0600);
            if(!empty($_SESSION['bp_stage']))@unlink($_SESSION['bp_stage']);
            $_SESSION['bp_stage']=$stage;$_SESSION['bp_stage_ts']=time();
        }
        if(!empty($_SESSION['bp_stage'])){
            if((time()-($_SESSION['bp_stage_ts']??0))>3600)throw new RuntimeException('Prévia expirada. Envie o arquivo novamente.');
            $stage=$_SESSION['bp_stage'];if(!is_file($stage))throw new RuntimeException('Arquivo temporário indisponível.');
            $rows=json_decode(file_get_contents($stage),true,512,JSON_THROW_ON_ERROR);
            [$linhas,$resumo]=bpValidate($pdo,$rows);
        }
        if($tipo==='baixar_relatorio'){
            if(!$linhas)throw new RuntimeException('Analise o arquivo antes de baixar o relatório.');
            header('Content-Type: text/csv; charset=UTF-8');
            header('Content-Disposition: attachment; filename=relatorio-importacao-beautypet.csv');
            $out=fopen('php://output','wb');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out,['linha_excel','codigo_antigo','cliente','pet','status','motivos'],';','"','');
            foreach($linhas as $line) fputcsv($out,[bpField($line['raw'],'linha_excel'),bpField($line['raw'],'codigo_antigo'),$line['nome'],$line['pet'],$line['estado'],implode(' | ',$line['motivos'])],';','"','');
            fclose($out);exit;
        }
        if($tipo==='confirmar'){
            if(!$linhas)throw new RuntimeException('Faça a análise antes de importar.');
            if(($_POST['confirmacao']??'')!=='IMPORTAR')throw new RuntimeException('Digite IMPORTAR para confirmar.');
            $gravado=bpCreate($pdo,$linhas);
            @unlink($_SESSION['bp_stage']);unset($_SESSION['bp_stage'],$_SESSION['bp_stage_ts']);
            $linhas=[];
        }
    } catch(Throwable $e){$erros[]=$e->getMessage();}
}
$tituloPagina='Importar cadastros';$paginaAtiva='importar-cadastros';
require __DIR__.'/../includes/topo.php';
?>
<main style="max-width:1100px;margin:36px auto;padding:22px;background:var(--card-bg,#fff);border-radius:14px">
<h1>Importar cadastros antigos</h1><p>Importação segura de clientes e pets. O upload faz apenas a análise; o banco só é alterado após confirmação.</p>
<?php foreach($erros as $err):?><p role="alert" style="color:#9b1c1c"><?=escapar($err)?></p><?php endforeach;?>
<?php if($gravado):?><h2>Importação concluída</h2><p>Clientes criados: <?=intval($gravado['createdClients'])?> · Pets criados: <?=intval($gravado['createdPets'])?> · Raças criadas: <?=intval($gravado['createdBreeds'])?> · Espécies criadas: <?=intval($gravado['createdSpecies'])?> · Somente tutor: <?=intval($gravado['clientOnly'])?> · Pets já existentes ignorados: <?=intval($gravado['skippedPets'])?></p><?php if(!empty($gravado['conflictingRows'])): ?><p style="color:#9b1c1c"><b>Registros separados para revisão: <?=count($gravado['conflictingRows'])?></b>. Nenhum destes registros foi vinculado a outro cliente.</p><table><thead><tr><th>Linha do Excel</th><th>Cliente</th><th>Motivo</th></tr></thead><tbody><?php foreach($gravado['conflictingRows'] as $c):?><tr><td><?=escapar($c['linha'])?></td><td><?=escapar($c['nome'])?></td><td><?=escapar($c['motivo'])?></td></tr><?php endforeach;?></tbody></table><?php endif;?><?php endif;?>
<form method="post" enctype="multipart/form-data" style="padding:18px;border:1px solid #ddd;border-radius:10px">
<input type="hidden" name="csrf" value="<?=escapar($csrf)?>"><input type="hidden" name="acao" value="analisar">
<label for="csv"><b>CSV preparado do Excel original (cadastros_beautypet_para_importacao.csv)</b></label><p><input id="csv" type="file" accept=".csv,text/csv" name="csv" required></p>
<button type="submit">Analisar sem gravar</button><p style="font-size:13px;color:#666">Não envie relatorio-importacao-beautypet.csv: esse arquivo é apenas o relatório de erros.</p>
</form>
<?php if($linhas):?>
<h2>Prévia de segurança</h2>
<p>Total: <b><?=$resumo['total']?></b> · Aprovados: <b><?=$resumo['aprovados']?></b> · Revisão: <b><?=$resumo['revisao']?></b> · Bloqueados: <b><?=$resumo['bloqueados']?></b></p>
<p><b>Linhas com campos opcionais faltando também podem ser aprovadas. Somente as linhas aprovadas serão importadas.</b> Revise o relatório antes de autorizar. Raças novas e espécies conhecidas (coelho, porquinho-da-índia, galo) são cadastradas após confirmação. Dados ausentes permanecem vazios; espécies desconhecidas e conflitos de identidade são bloqueados. Letras maiúsculas/minúsculas, acentos e espaços são unificados dentro de cada espécie; nomes de raça vazios permanecem sem raça.</p>
<form method="post" style="margin:12px 0"><input type="hidden" name="csrf" value="<?=escapar($csrf)?>"><input type="hidden" name="acao" value="baixar_relatorio"><button type="submit">Baixar relatório CSV de conferência</button></form>
<div style="max-height:470px;overflow:auto"><table style="width:100%;border-collapse:collapse"><thead><tr><th>Linha</th><th>Tutor</th><th>Pet</th><th>Status</th><th>Motivos</th></tr></thead><tbody>
<?php foreach($linhas as $item):?><tr style="border-bottom:1px solid #eee"><td><?=escapar(bpField($item['raw'],'linha_excel'))?></td><td><?=escapar($item['nome'])?></td><td><?=escapar($item['pet'])?></td><td><?=escapar($item['estado'])?></td><td><?=escapar(implode('; ',$item['motivos']))?></td></tr><?php endforeach;?></tbody></table></div>
<?php if($resumo['aprovados']):?>
<form method="post" onsubmit="return confirm('Confirmar gravação real de <?=intval($resumo['aprovados'])?> registros aprovados? Faça backup do banco antes.');" style="margin-top:24px;padding:18px;border:2px solid #d99a40;border-radius:10px">
<input type="hidden" name="csrf" value="<?=escapar($csrf)?>"><input type="hidden" name="acao" value="confirmar">
<p>Faça um backup do Supabase. Para confirmar, digite <b>IMPORTAR</b>:</p>
<input name="confirmacao" required autocomplete="off" pattern="IMPORTAR" placeholder="IMPORTAR"> <button type="submit">Importar somente os aprovados</button>
</form><?php endif;?><?php endif;?>
</main>
<?php require __DIR__.'/../includes/rodape.php'; ?>
