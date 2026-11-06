<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
<div class="asaas-charges-navigation">
    <ul class="nav nav-tabs" role="tablist" aria-label="Parâmetros de cobrança">
        <li role="presentation" class="active">
            <a id="asaas-charges-tab-general" href="#asaas-charges-general" role="tab" data-toggle="tab"
               data-asaas-charges-group="general" aria-controls="asaas-charges-general"
               aria-selected="true" tabindex="0">Encargos e descontos</a>
        </li>
        <li role="presentation">
            <a id="asaas-charges-tab-card" href="#asaas-charges-card" role="tab" data-toggle="tab"
               data-asaas-charges-group="card" aria-controls="asaas-charges-card"
               aria-selected="false" tabindex="-1">Cartão de crédito</a>
        </li>
    </ul>
</div>
<div class="tab-content asaas-charges-content mtop20">
    <div id="asaas-charges-general" class="tab-pane active" role="tabpanel"
         data-asaas-charges-panel="general" aria-labelledby="asaas-charges-tab-general"></div>
    <div id="asaas-charges-card" class="tab-pane" role="tabpanel" hidden
         data-asaas-charges-panel="card" aria-labelledby="asaas-charges-tab-card">
        <p class="help-block">Configure as opções de cartão disponíveis para o cliente. O total e os valores das parcelas são apresentados antes da confirmação do pagamento.</p>
        <section class="asaas-card-settings-section" aria-labelledby="asaas-card-installments-title">
            <h4 id="asaas-card-installments-title">Parcelamento</h4>
            <div class="asaas-card-settings-grid" data-asaas-card-section="installments"></div>
        </section>
        <section class="asaas-card-settings-section" aria-labelledby="asaas-card-fees-title">
            <h4 id="asaas-card-fees-title">Repasse e taxa fixa</h4>
            <div class="asaas-card-settings-grid" data-asaas-card-section="fees"></div>
        </section>
        <section class="asaas-card-settings-section" aria-labelledby="asaas-card-rates-title">
            <h4 id="asaas-card-rates-title">Taxa percentual por faixa de parcelas</h4>
            <div class="asaas-card-settings-grid" data-asaas-card-section="rates"></div>
        </section>
        <section class="asaas-card-settings-section" aria-labelledby="asaas-card-anticipation-title">
            <h4 id="asaas-card-anticipation-title">Antecipação de recebíveis</h4>
            <div class="asaas-card-settings-grid" data-asaas-card-section="anticipation"></div>
        </section>
    </div>
</div>
