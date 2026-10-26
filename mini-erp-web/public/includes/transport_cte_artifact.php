<?php
declare(strict_types=1);
$cteArtifact=$document['snapshot']['cte_artifact']??null;
$cteImported=($document['snapshot']['source']??'')==='CTE_XML_IMPORT';
?>
<section class="transport-section">
<h3><?=$cteImported?'CT-e importado':'XML e DACTE'?></h3>
<?php if(!$cteImported):?>
<p>Gere o XML de teste a partir da versão salva. A prévia usa numeração interna e ambiente de homologação, sem assinatura ou transmissão. Ao editar e salvar, gere novamente.</p>
<form method="post"><input type="hidden" name="action" value="transport_cte_generate"><input type="hidden" name="document_id" value="<?=(int)$document['id']?>"><input type="hidden" name="revision" value="<?=(int)$document['revision']?>"><input type="hidden" name="csrf_token" value="<?=$te($_SESSION['erp_transport_csrf'])?>"><button class="btn primary" <?=$transportReadOnly?'disabled':''?>><?=$cteArtifact?'Atualizar XML de teste':'Gerar XML de teste'?></button></form>
<?php else:?><p>O arquivo original está preservado para consulta e download. A importação não altera os cadastros de pessoas nem confirma a autorização pela SEFAZ.</p><?php endif?>
<?php if($cteArtifact):$meta=$cteArtifact['metadata'];?>
<dl class="transport-xml-summary">
<dt>Chave de acesso</dt><dd><?=$te($meta['key'])?></dd>
<dt>Número / série</dt><dd><?=$te($meta['number'])?> / <?=$te($meta['series'])?><?=$cteImported?'':' — numeração de prévia'?></dd>
<dt>Emitente</dt><dd><?=$te($meta['issuer_name'])?></dd>
<dt>Destinatário</dt><dd><?=$te($meta['recipient_name'])?></dd>
<dt>Valor da prestação</dt><dd>R$ <?=$te(number_format((float)$meta['service_value'],2,',','.'))?></dd>
<dt>Ambiente do XML</dt><dd><?=$meta['environment']==='2'?'Homologação':'Produção (arquivo importado)'?></dd>
<dt>Estrutura antes da assinatura</dt><dd><?=$meta['unsigned_xsd_valid']?'Conferida no schema instalado':'Com pendências'?></dd>
<dt>Validação XSD completa</dt><dd><?=$meta['xsd_valid']?'Estrutura completa conferida':'Pendente — consulte os detalhes abaixo'?></dd>
<?php if($meta['protocol']):?><dt>Protocolo informado no arquivo</dt><dd><?=$te($meta['protocol'])?> · Código <?=$te($meta['protocol_status'])?> (não consultado na SEFAZ)</dd><?php endif?>
</dl>
<p>Assinatura digital e autorização não foram verificadas. O DACTE será exibido como prévia.</p>
<div class="transport-actions"><a class="btn primary" target="_blank" rel="noopener" href="?page=transporte&amp;tab=cte&amp;document=<?=(int)$document['id']?>&amp;cte_file=pdf">Visualizar DACTE (PDF)</a><a class="btn secondary" href="?page=transporte&amp;tab=cte&amp;document=<?=(int)$document['id']?>&amp;cte_file=xml">Baixar XML<?=$cteImported?' original':' de teste'?></a></div>
<details><summary>Detalhes da validação do XML</summary><p>Schema: <?=$te($meta['schema'])?>. A conferência usa esta versão instalada e não substitui as regras de autorização vigentes.</p><?php if(!$meta['signature_present']):?><p>Este XML não contém assinatura digital. A validação completa permanece pendente até a etapa de assinatura.</p><?php endif?><?php foreach($meta['xsd_errors'] as $error):?><p class="transport-xml-diagnostic"><?=$te($error)?></p><?php endforeach?><p class="transport-xml-diagnostic">SHA-256: <?=$te($meta['sha256'])?></p></details>
<?php endif?>
</section>
