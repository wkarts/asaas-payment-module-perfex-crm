<?php

defined('BASEPATH') or exit('No direct script access allowed');
if (!is_admin()) {
    access_denied('Asaas');
    return;
}
init_head();
?>
<div id="wrapper">
    <div class="content">
        <div class="row">
            <div class="col-md-12">
                <div class="panel_s">
                    <div class="panel-body">
                        <h4 class="no-margin"><?php echo html_escape($title); ?></h4>
                        <hr class="hr-panel-heading">
                        <p>Últimos 100 registros da integração, do mais recente para o mais antigo. Chaves, tokens e dados pessoais são ocultados.</p>
                        <p>
                            <a class="btn btn-default" href="<?php echo html_escape(admin_url('settings?group=payment_gateways')); ?>">Voltar às configurações de pagamento</a>
                            <a class="btn btn-default" href="<?php echo html_escape(admin_url('asaas/asaas/logs')); ?>">Atualizar diagnóstico</a>
                        </p>
                        <?php if ($database_state !== 'ok') { ?>
                            <div class="alert alert-warning" role="alert">
                                <?php if ($database_state === 'missing') { ?>
                                    A tabela de logs do módulo ainda não está disponível. Verifique se a atualização das migrations do módulo foi concluída.
                                <?php } else { ?>
                                    Não foi possível consultar os logs no banco. Verifique as permissões de leitura e escrita e a atualização das migrations do módulo.
                                <?php } ?>
                                Uma falha no armazenamento do diagnóstico não deve interromper a cobrança. Erros e falhas de armazenamento também são encaminhados ao log de erros do PHP.
                            </div>
                        <?php } ?>
                        <div class="alert alert-info">
                            <p><strong>Log em arquivo: <?php echo $file_enabled ? 'habilitado' : 'desabilitado'; ?>.</strong>
                                A opção “Habilitar log em arquivo” está nas configurações do meio de pagamento Asaas.</p>
                            <?php if ($file_path !== null) { ?>
                                <p>Arquivo do dia: <code style="overflow-wrap:anywhere;word-break:break-all"><?php echo html_escape($file_path); ?></code></p>
                                <p><?php echo $file_exists ? 'O arquivo do dia já existe.' : 'O arquivo será criado no primeiro registro com a opção habilitada.'; ?>
                                    <?php echo $directory_writable ? 'O diretório permite escrita.' : 'O diretório não existe ou não permite escrita pelo PHP; solicite a correção da permissão ao administrador do servidor.'; ?></p>
                            <?php } else { ?>
                                <p>O diretório local de logs não pôde ser determinado. Revise a configuração <code>log_path</code> do CodeIgniter.</p>
                            <?php } ?>
                            <p>O arquivo usa proteção PHP contra acesso direto. Preserve também a proteção do diretório de logs no servidor. Os arquivos padrão do CodeIgniter dependem do <code>log_threshold</code> configurado.</p>
                        </div>
                        <?php if ($source === 'file') { ?>
                            <div class="alert alert-info">Exibindo os registros mais recentes do arquivo Asaas do dia, pois a consulta ao banco está indisponível. A leitura é limitada aos últimos 256 KiB e a 100 registros.</div>
                        <?php } ?>
                        <?php if ($rows === []) { ?>
                            <div class="alert alert-info">Nenhum registro foi encontrado. Após uma nova tentativa de integração, atualize esta tela para consultar o resultado.</div>
                        <?php } ?>
                        <?php if ($rows !== []) { ?>
                            <div class="table-responsive">
                                <table class="table table-striped">
                                    <thead><tr><th>Data</th><th>Nível</th><th>Diagnóstico</th><th>Correlação</th></tr></thead>
                                    <tbody>
                                    <?php foreach ($rows as $row) { ?>
                                        <tr>
                                            <td><?php echo html_escape((string) ($row['created_at'] ?? '')); ?></td>
                                            <td><?php echo html_escape(strtoupper((string) ($row['level'] ?? ''))); ?></td>
                                            <td style="min-width:230px;max-width:760px;overflow-wrap:anywhere">
                                                <p><?php echo html_escape((string) ($row['message'] ?? '')); ?></p>
                                                <?php if (!empty($row['context'])) { ?>
                                                    <pre style="white-space:pre-wrap;overflow-wrap:anywhere;word-break:break-word"><?php echo html_escape((string) json_encode($row['context'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE)); ?></pre>
                                                <?php } ?>
                                            </td>
                                            <td style="overflow-wrap:anywhere"><?php echo html_escape((string) ($row['correlation_id'] ?? '')); ?></td>
                                        </tr>
                                    <?php } ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php } ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
<?php init_tail(); ?>
</body>
</html>
