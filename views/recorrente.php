<?php
defined('BASEPATH') or exit('No direct script access allowed');

$recurring = isset($recurring_page) && is_array($recurring_page) ? $recurring_page : [];
$requiresLogin = !empty($requires_login);
$formNonce = isset($recurring_csrf_nonce) && is_string($recurring_csrf_nonce)
    && preg_match('/^[a-f0-9]{64}$/D', $recurring_csrf_nonce) ? $recurring_csrf_nonce : '';
$canEnroll = !$requiresLogin && $formNonce !== '' && !empty($recurring['can_enroll']);
$canRevoke = !$requiresLogin && $formNonce !== '' && !empty($recurring['can_revoke']);
$uncertain = in_array($recurring['state'] ?? '', ['processing', 'uncertain'], true)
    || in_array($recurring['attempt_state'] ?? '', ['preparing', 'processing', 'ambiguous', 'pending', 'uncertain'], true);
$canEnroll = $canEnroll && !$uncertain;
$canPayOnce = !$requiresLogin && !empty($recurring['can_pay_now']) && !$uncertain;
$stateLabels = [
    'inactive' => 'Ainda não autorizado', 'active' => 'Recorrência ativa', 'suspended' => 'Recorrência suspensa',
    'revoked' => 'Recorrência desativada', 'processing' => 'Pagamento em processamento',
    'uncertain' => 'Confirmação pendente', 'confirmed' => 'Pagamento confirmado',
    'declined' => 'Pagamento recusado', 'unavailable' => 'Recorrência indisponível',
];
$authorizationLabels = ['active' => 'Ativa', 'suspended' => 'Suspensa', 'revoked' => 'Desativada', 'inactive' => 'Não cadastrada'];
$state = is_string($recurring['state'] ?? null) ? $recurring['state'] : 'unavailable';
$authorization = is_string($recurring['authorization_state'] ?? null) ? $recurring['authorization_state'] : 'inactive';
$holderDefaults = isset($card_holder_defaults) && is_array($card_holder_defaults) ? $card_holder_defaults : [];
$holderValue = static function (string $key) use ($holderDefaults): string {
    return isset($holderDefaults[$key]) && is_scalar($holderDefaults[$key]) ? html_escape((string) $holderDefaults[$key]) : '';
};
$money = static function ($value): string {
    return is_string($value) && preg_match('/^\d{1,12}\.\d{2}$/D', $value)
        ? 'R$ ' . number_format((float) $value, 2, ',', '.') : '—';
};
$consentText = is_string($recurring['consent_text'] ?? null) && $recurring['consent_text'] !== ''
    ? $recurring['consent_text']
    : 'Autorizo o pagamento desta fatura e a cobrança automática das próximas faturas Asaas elegíveis deste cliente no cartão informado, em uma parcela por fatura, com os encargos apresentados. Posso desativar a autorização para cobranças futuras nesta página.';
$assetRoot = 'modules/asaas/assets/';
?>
<link rel="stylesheet" href="<?php echo html_escape(site_url($assetRoot . 'css/asaas-recurring.css') . '?v=' . filemtime(__DIR__ . '/../assets/css/asaas-recurring.css')); ?>">
<div id="asaas-recurring" class="container asaas-recurring-container mtop25 mbot30">
    <div class="row"><div class="col-md-10 col-md-offset-1">
        <div class="panel_s"><div class="panel-body">
            <div class="asaas-recurring-heading">
                <div>
                    <p class="text-muted no-mbot"><?php echo html_escape(format_invoice_number($invoice->id)); ?></p>
                    <h2 class="bold mtop5">Cartão de crédito recorrente</h2>
                </div>
                <a href="<?php echo html_escape($invoice_url); ?>" class="btn btn-default">Voltar à fatura</a>
            </div>
            <?php if ($requiresLogin) { ?>
                <div class="alert alert-info mtop20" role="status">
                    <h4 class="bold">Entre na conta do cliente para autorizar a recorrência</h4>
                    <p>Essa autorização permite cobrar automaticamente novas faturas deste cliente. Por isso, o cadastro e a desativação do cartão recorrente exigem acesso à conta do cliente com permissão para faturas.</p>
                </div>
                <div class="asaas-recurring-actions">
                    <a href="<?php echo html_escape($login_url); ?>" class="btn btn-primary">Entrar e continuar</a>
                    <a href="<?php echo html_escape($invoice_url); ?>" class="btn btn-default">Voltar para o pagamento pontual</a>
                </div>
            <?php } else { ?>
                <?php $asaas_environment_hint = ''; include __DIR__ . '/partials/payment_environment.php'; ?>
                <div class="clearfix"></div>
                <div class="asaas-recurring-state mtop20">
                    <div>
                        <span class="text-muted">Situação</span>
                        <strong class="display-block"><?php echo html_escape($stateLabels[$state] ?? 'Conferência necessária'); ?></strong>
                    </div>
                    <div>
                        <span class="text-muted">Autorização de cobranças futuras</span>
                        <strong class="display-block"><?php echo html_escape($authorizationLabels[$authorization] ?? 'Conferência necessária'); ?></strong>
                    </div>
                    <div>
                        <span class="text-muted">Fatura no CRM</span>
                        <div><?php echo format_invoice_status($invoice->status, '', true); ?></div>
                    </div>
                </div>
                <?php if (!empty($mensagem_erro)) { ?>
                    <div class="alert alert-danger mtop15" role="alert"><?php echo html_escape($mensagem_erro); ?></div>
                <?php } ?>
                <?php if (!empty($mensagem_info)) { ?>
                    <div class="alert alert-success mtop15" role="status"><?php echo html_escape($mensagem_info); ?></div>
                <?php } ?>
                <?php if (!empty($recurring['message'])) { ?>
                    <div class="alert alert-info mtop15" role="status"><?php echo html_escape($recurring['message']); ?></div>
                <?php } ?>
                <?php if ($uncertain) { ?>
                    <div class="alert alert-warning" role="status">A confirmação do pagamento está em acompanhamento. Aguarde a conferência antes de enviar outra tentativa ou pagar por outro meio. Desativar a recorrência impede cobranças futuras e não cancela uma operação já iniciada.</div>
                <?php } ?>
                <div class="asaas-recurring-explanation mtop20">
                    <h3 class="bold">Como funciona</h3>
                    <ul>
                        <li>Ao autorizar, esta fatura será cobrada no cartão em uma parcela.</li>
                        <li>As próximas faturas Asaas elegíveis emitidas para este cliente terão uma tentativa automática de cobrança, conforme a autorização.</li>
                        <li>Se o cartão for recusado, a recorrência será suspensa. Você poderá cadastrar o cartão novamente em uma fatura aberta ou usar o pagamento pontual.</li>
                    </ul>
                </div>
                <?php if ($canEnroll) { ?>
                    <div class="asaas-recurring-price mtop20" aria-label="Valores desta cobrança">
                        <div><span>Saldo desta fatura</span><strong><?php echo html_escape($money($recurring['principal'] ?? '')); ?></strong></div>
                        <div><span>Encargos do cartão</span><strong><?php echo html_escape($money($recurring['fee'] ?? '')); ?></strong></div>
                        <div class="asaas-recurring-total"><span>Total desta cobrança em 1x</span><strong><?php echo html_escape($money($recurring['total'] ?? '')); ?></strong></div>
                    </div>
                    <form id="asaas-recurring-enroll" data-asaas-recurring-form="enroll" action="<?php echo html_escape($recurring_url); ?>" method="post" autocomplete="off" class="mtop25">
                        <input type="hidden" name="<?php echo html_escape($this->security->get_csrf_token_name()); ?>" value="<?php echo html_escape($this->security->get_csrf_hash()); ?>">
                        <input type="hidden" name="recurringNonce" value="<?php echo html_escape($formNonce); ?>">
                        <input type="hidden" name="action" value="enroll">
                        <input type="hidden" name="consentVersion" value="<?php echo html_escape($recurring['consent_version'] ?? ''); ?>">
                        <input type="hidden" name="cardQuote" value="<?php echo html_escape($recurring['quote'] ?? ''); ?>">
                        <h3 class="bold">Cadastrar cartão</h3>
                        <p class="text-muted">Informe o cartão que deseja autorizar. Para atualizar uma autorização existente, preencha os dados do novo cartão.</p>
                        <div class="form-group">
                            <label for="recurring-holderName" class="control-label">Nome impresso no cartão</label>
                            <input id="recurring-holderName" name="holderName" type="text" class="form-control" autocomplete="off" maxlength="100" required>
                        </div>
                        <div class="form-group">
                            <label for="recurring-cardNumber" class="control-label">Número do cartão</label>
                            <input id="recurring-cardNumber" name="cardNumber" type="text" class="form-control" autocomplete="off" inputmode="numeric" pattern="[ \-]*(?:[0-9][ \-]*){13,19}" maxlength="37" required>
                        </div>
                        <div class="row">
                            <div class="col-xs-4"><div class="form-group">
                                <label for="recurring-expirationMonth" class="control-label">Validade: mês</label>
                                <input id="recurring-expirationMonth" name="expirationMonth" type="text" class="form-control" placeholder="MM" autocomplete="off" inputmode="numeric" pattern="(?:0?[1-9]|1[0-2])" maxlength="2" required>
                            </div></div>
                            <div class="col-xs-4"><div class="form-group">
                                <label for="recurring-expirationYear" class="control-label">Validade: ano</label>
                                <input id="recurring-expirationYear" name="expirationYear" type="text" class="form-control" placeholder="AAAA" autocomplete="off" inputmode="numeric" pattern="[0-9]{4}" maxlength="4" required>
                            </div></div>
                            <div class="col-xs-4"><div class="form-group">
                                <label for="recurring-securityCode" class="control-label">CVV</label>
                                <input id="recurring-securityCode" name="securityCode" type="password" class="form-control" autocomplete="off" inputmode="numeric" pattern="[0-9]{3,4}" minlength="3" maxlength="4" required>
                            </div></div>
                        </div>
                        <h4 class="bold">Dados do titular</h4>
                        <div class="row">
                            <div class="col-sm-6"><div class="form-group">
                                <label for="recurring-holderEmail" class="control-label">E-mail</label>
                                <input id="recurring-holderEmail" name="holderEmail" type="email" class="form-control" autocomplete="off" maxlength="254" value="<?php echo $holderValue('holderEmail'); ?>" required>
                            </div></div>
                            <div class="col-sm-6"><div class="form-group">
                                <label for="recurring-holderDocument" class="control-label">CPF ou CNPJ</label>
                                <input id="recurring-holderDocument" name="holderDocument" type="text" class="form-control" autocomplete="off" inputmode="numeric" maxlength="18" value="<?php echo $holderValue('holderDocument'); ?>" required>
                            </div></div>
                        </div>
                        <div class="row">
                            <div class="col-sm-4"><div class="form-group">
                                <label for="recurring-holderPostalCode" class="control-label">CEP</label>
                                <input id="recurring-holderPostalCode" name="holderPostalCode" type="text" class="form-control" autocomplete="off" inputmode="numeric" maxlength="9" value="<?php echo $holderValue('holderPostalCode'); ?>" required>
                            </div></div>
                            <div class="col-sm-4"><div class="form-group">
                                <label for="recurring-holderAddressNumber" class="control-label">Número do endereço</label>
                                <input id="recurring-holderAddressNumber" name="holderAddressNumber" type="text" class="form-control" autocomplete="off" maxlength="20" value="<?php echo $holderValue('holderAddressNumber'); ?>" required>
                            </div></div>
                            <div class="col-sm-4"><div class="form-group">
                                <label for="recurring-holderPhone" class="control-label">Telefone com DDD</label>
                                <input id="recurring-holderPhone" name="holderPhone" type="tel" class="form-control" autocomplete="off" maxlength="20" value="<?php echo $holderValue('holderPhone'); ?>" required>
                            </div></div>
                        </div>
                        <p class="text-muted asaas-recurring-privacy">O módulo mantém a autorização e um token criptografado fornecido pelo Asaas para realizar novas cobranças. Número do cartão, validade e CVV são enviados nesta solicitação e não são gravados pelo módulo.</p>
                        <div class="checkbox checkbox-primary asaas-recurring-consent">
                            <input id="recurring-consent" name="recurringConsent" type="checkbox" value="1" required aria-describedby="asaas-recurring-consent-help">
                            <label for="recurring-consent"><?php echo html_escape($consentText); ?></label>
                        </div>
                        <p id="asaas-recurring-consent-help" class="text-muted">Esta autorização se aplica a este cliente e às próximas faturas elegíveis. As faturas anteriores não são cobradas em lote por este cadastro. Desativar a autorização não exclui faturas nem desfaz pagamentos.</p>
                        <button type="submit" class="btn btn-primary" data-recurring-submit><?php echo $authorization === 'active' ? 'Atualizar cartão e pagar esta fatura' : 'Autorizar e pagar esta fatura'; ?></button>
                    </form>
                <?php } elseif ((int) $invoice->status === 2 && $canRevoke) { ?>
                    <p class="text-muted mtop15">Esta fatura está quitada. Você pode desativar as cobranças futuras abaixo. Para cadastrar outro cartão, acesse uma próxima fatura em aberto.</p>
                <?php } ?>
                <?php if ($canPayOnce) { ?>
                    <div class="asaas-recurring-alternative mtop20">
                        <p class="no-mbot">Prefere pagar somente esta fatura?</p>
                        <a href="<?php echo html_escape($invoice_url); ?>" class="btn btn-default mtop10">Voltar ao pagamento pontual</a>
                        <p class="text-muted mtop10 no-mbot">Na fatura, escolha o meio Asaas habitual para acessar as opções de boleto, Pix ou cartão disponíveis.</p>
                    </div>
                <?php } ?>
                <?php if ($canRevoke) { ?>
                    <div class="asaas-recurring-revoke mtop25">
                        <h4 class="bold">Gerenciar a autorização</h4>
                        <p>Desativar impede novas tentativas automáticas. Uma operação já iniciada continua em acompanhamento até a confirmação do resultado.</p>
                        <form id="asaas-recurring-revoke" data-asaas-recurring-form="revoke" action="<?php echo html_escape($recurring_url); ?>" method="post" autocomplete="off">
                            <input type="hidden" name="<?php echo html_escape($this->security->get_csrf_token_name()); ?>" value="<?php echo html_escape($this->security->get_csrf_hash()); ?>">
                            <input type="hidden" name="recurringNonce" value="<?php echo html_escape($formNonce); ?>">
                            <input type="hidden" name="action" value="revoke">
                            <button type="submit" class="btn btn-default" data-recurring-submit>Desativar cobranças automáticas</button>
                        </form>
                    </div>
                <?php } ?>
                <p id="asaas-recurring-status" class="alert alert-info mtop15 hidden" role="status" aria-live="polite"></p>
                <?php if ($canEnroll || $canRevoke) { ?>
                    <noscript><p class="text-muted mtop15">Após confirmar, aguarde a resposta. Não atualize nem envie o formulário novamente enquanto a operação estiver em andamento.</p></noscript>
                <?php } ?>
            <?php } ?>
        </div></div>
    </div></div>
</div>
<?php if ($canEnroll || $canRevoke) { ?>
<script src="<?php echo html_escape(site_url($assetRoot . 'js/asaas-recurring.js') . '?v=' . filemtime(__DIR__ . '/../assets/js/asaas-recurring.js')); ?>"></script>
<?php } ?>
