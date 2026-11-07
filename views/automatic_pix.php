<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
<link rel="stylesheet" href="<?php echo html_escape(module_dir_url('asaas', 'assets/css/asaas-automatic-pix.css') . '?v=' . filemtime(__DIR__ . '/../assets/css/asaas-automatic-pix.css')); ?>">
<div class="asaas-automatic-pix-page">
    <div class="panel_s"><div class="panel-body">
        <div class="asaas-pix-heading">
            <div><p class="text-muted"><?php echo html_escape(format_invoice_number($invoice->id)); ?></p><h2>Pix Automático</h2></div>
            <a class="btn btn-default" href="<?php echo html_escape($invoice_url); ?>">Voltar à fatura</a>
        </div>
        <?php if ($requires_login) { ?>
            <div class="alert alert-info"><strong>Entre na sua conta para autorizar</strong><p>Pague esta fatura por Pix e autorize no aplicativo do banco os próximos pagamentos. O cadastro e o cancelamento exigem acesso à conta deste cliente.</p></div>
            <a class="btn btn-primary" href="<?php echo html_escape($login_url); ?>">Entrar e continuar</a>
        <?php } else { $page = $pix_page; ?>
            <?php $notice = $this->session->flashdata('asaas_pix_notice'); if (is_string($notice) && $notice !== '') { ?><div class="alert alert-info" role="status"><?php echo html_escape($notice); ?></div><?php } ?>
            <div class="alert alert-info" role="status"><?php echo html_escape($page['message']); ?></div>
            <?php if ($page['payload'] !== '') { ?>
                <div class="asaas-pix-qr-grid">
                    <?php if ($page['encoded_image'] !== '') { ?><img class="asaas-pix-qr" src="data:image/png;base64,<?php echo html_escape($page['encoded_image']); ?>" alt="QR Code para pagar a fatura e autorizar o Pix Automático"><?php } ?>
                    <div class="asaas-pix-qr-details"><h3>Pagamento inicial: R$ <?php echo html_escape(number_format((float) $page['amount'], 2, ',', '.')); ?></h3>
                        <p>Escaneie o código no aplicativo do banco. Após o pagamento, conclua a autorização dos próximos débitos na frequência <?php echo html_escape(mb_strtolower(\Asaas\AsaasAutomaticPix::FREQUENCIES[$page['frequency']] ?? 'escolhida')); ?>.</p>
                        <label for="asaas-pix-copy">Pix copia e cola</label>
                        <textarea id="asaas-pix-copy" class="form-control asaas-pix-payload" rows="3" readonly autocomplete="off" spellcheck="false"><?php echo html_escape($page['payload']); ?></textarea>
                        <p class="text-muted">Válido até <?php echo html_escape($page['expires_at']); ?> (horário de Brasília).</p>
                        <p class="text-muted">O pagamento desta fatura não garante a ativação da autorização. Use “Atualizar situação” para conferir as duas confirmações.</p>
                    </div>
                </div>
            <?php } ?>
            <?php if ($page['can_enroll']) { ?>
                <?php echo form_open($pix_url, ['class' => 'asaas-pix-form', 'autocomplete' => 'off']); ?>
                    <input type="hidden" name="action" value="enroll"><input type="hidden" name="pixNonce" value="<?php echo html_escape($pix_nonce); ?>">
                    <input type="hidden" name="pixQuote" value="<?php echo html_escape($page['quote']); ?>"><input type="hidden" name="consentVersion" value="<?php echo html_escape($page['consent_version']); ?>">
                    <div class="asaas-pix-enroll-grid">
                        <div><span class="text-muted">Pagamento inicial</span><h3>R$ <?php echo html_escape(number_format((float) $page['amount'], 2, ',', '.')); ?></h3></div>
                        <div class="form-group"><label for="asaas-pix-frequency">Frequência das próximas faturas</label>
                            <select id="asaas-pix-frequency" class="form-control" name="frequency" required>
                                <?php foreach (\Asaas\AsaasAutomaticPix::FREQUENCIES as $key => $label) { ?><option value="<?php echo html_escape($key); ?>" <?php echo $key === 'MONTHLY' ? 'selected' : ''; ?>><?php echo html_escape($label); ?></option><?php } ?>
                            </select>
                        </div>
                    </div>
                    <p class="text-muted">Os próximos valores seguem as faturas emitidas. O débito depende de autorização ativa, do limite definido no banco e da antecedência necessária para o agendamento. Uma fatura por ciclo.</p>
                    <div class="checkbox checkbox-primary"><input type="checkbox" id="asaas-pix-consent" name="pixConsent" value="1" required><label for="asaas-pix-consent"><?php echo html_escape($page['consent_text']); ?></label></div>
                    <button class="btn btn-primary" type="submit">Gerar QR Code de adesão</button>
                <?php echo form_close(); ?>
            <?php } ?>
            <?php if ($page['can_refresh'] || $page['can_cancel']) { ?>
                <?php echo form_open($pix_url, ['class' => 'asaas-pix-actions']); ?>
                    <input type="hidden" name="pixNonce" value="<?php echo html_escape($pix_nonce); ?>">
                    <?php if ($page['can_refresh']) { ?><button type="submit" class="btn btn-default" name="action" value="refresh">Atualizar situação</button><?php } ?>
                    <?php if ($page['can_cancel']) { ?><button type="submit" class="btn btn-danger" name="action" value="cancel">Solicitar cancelamento da autorização</button><?php } ?>
                <?php echo form_close(); ?>
            <?php } ?>
            <?php if ($page['can_pay_now']) { ?><p class="mtop20"><a href="<?php echo html_escape($invoice_url); ?>">Pagar somente esta fatura pelos meios disponíveis</a></p><?php } ?>
        <?php } ?>
    </div></div>
</div>
