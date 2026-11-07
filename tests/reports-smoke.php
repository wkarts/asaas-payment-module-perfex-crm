<?php

declare(strict_types=1);

/** Real SDK facades and Guzzle MockHandler. No account, CRM database or live API is accessed. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
error_reporting(E_ALL);
set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    if (!(error_reporting() & $severity)) { return false; }
    throw new ErrorException($message, 0, $severity, $file, $line);
});
define('BASEPATH', __DIR__);
require_once dirname(__DIR__) . '/vendor/autoload.php';
require_once dirname(__DIR__) . '/libraries/Asaas/AsaasReports.php';

use Asaas\AsaasReportCatalog;
use Asaas\AsaasReportException;
use Asaas\AsaasReports;
use Asaas\Sdk\Http\Client;
use Asaas\Sdk\Http\Environment;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;

const REPORT_FIXTURE_KEY = '$aact_reports_public_fixture_only';
const REPORT_FIXTURE_CARD = '4111111111111111';
const REPORT_FIXTURE_TOKEN = 'opaque-card-token-fixture';
$assertions = 0;
$scenarios = 0;
$GLOBALS['reports_audit'] = [];
function asaas_diagnostic_log(string $level, string $message, array $context): void { $GLOBALS['reports_audit'][] = [$level, $message, $context]; }
function reportCheck(bool $condition, string $message): void
{
    $GLOBALS['assertions']++;
    if (!$condition) { throw new RuntimeException($message); }
}
function reportScenario(string $name, callable $work): void { $work(); $GLOBALS['scenarios']++; echo 'OK  ' . $name . PHP_EOL; }
function reportResponse(array $body, int $status = 200): Response { return new Response($status, ['Content-Type' => 'application/json'], json_encode($body, JSON_THROW_ON_ERROR)); }
function reportList(array $rows, int $offset = 0, ?int $total = null, bool $more = false): array
{
    return ['object' => 'list', 'data' => $rows, 'offset' => $offset, 'limit' => 100, 'totalCount' => $total ?? ($offset + count($rows)), 'hasMore' => $more];
}
function reportFixture(array $responses, ?array &$history, ?callable $clock = null): AsaasReports
{
    $history = [];
    $stack = HandlerStack::create(new MockHandler($responses));
    $stack->push(Middleware::history($history));
    $transport = new GuzzleClient(['handler' => $stack, 'base_uri' => Environment::Sandbox->value, 'allow_redirects' => false]);
    $client = new Client(REPORT_FIXTURE_KEY, Environment::Sandbox, 'ReportsOfflineFixture', 1.0, 1.0, null, $transport);
    $sdk = new stdClass();
    $facades = ['financialTransaction' => 'FinancialTransaction', 'finance' => 'Finance', 'payment' => 'Payment',
        'paymentSplit' => 'PaymentSplit', 'transfer' => 'Transfer', 'anticipation' => 'Anticipation', 'bill' => 'Bill',
        'installment' => 'Installment', 'subscription' => 'Subscription', 'pixTransaction' => 'PixTransaction',
        'automaticPix' => 'AutomaticPix', 'chargeback' => 'Chargeback', 'paymentRefund' => 'PaymentRefund',
        'invoice' => 'Invoice', 'paymentDunning' => 'PaymentDunning', 'mobilePhoneRecharge' => 'MobilePhoneRecharge'];
    foreach ($facades as $property => $name) { $class = 'Asaas\\Sdk\\Service\\' . $name . 'Service'; $sdk->$property = new $class($client); }
    return new AsaasReports(new stdClass(), $sdk, $clock, 'sandbox');
}
function reportQuery(array $history, int $index = 0): array
{
    $request = $history[$index]['request'];
    reportCheck($request->getMethod() === 'GET', 'Relatórios só podem consultar recursos financeiros via GET.');
    reportCheck((string) $request->getBody() === '', 'GET financeiro não deve enviar corpo.');
    $result = [];
    foreach (explode('&', $request->getUri()->getQuery()) as $pair) {
        if ($pair === '') { continue; }
        [$key, $value] = array_pad(explode('=', $pair, 2), 2, '');
        $result[rawurldecode($key)] = rawurldecode($value);
    }
    return $result;
}
function reportThrows(callable $work, string $reason): AsaasReportException
{
    try { $work(); } catch (AsaasReportException $error) {
        reportCheck($error->reason === $reason, 'Motivo esperado: ' . $reason . '; recebido: ' . $error->reason);
        reportCheck($error->getPrevious() === null, 'Erro não pode reter exceção original com credenciais.');
        return $error;
    }
    throw new RuntimeException('A operação deveria ter sido interrompida: ' . $reason);
}

reportScenario('cobranças preservam filtros exatos, datas e booleano false no transporte real', static function (): void {
    $service = reportFixture([reportResponse(reportList([['id' => 'pay_1', 'customer' => 'cus_fixture', 'status' => 'RECEIVED', 'value' => 100.25,
        'netValue' => 98.26, 'creditCard' => ['number' => REPORT_FIXTURE_CARD, 'ccv' => '847', 'creditCardToken' => REPORT_FIXTURE_TOKEN]]]))], $history);
    $page = $service->page('payments', ['customer' => 'cus_fixture', 'externalReference' => 'Fatura & filial 1', 'anticipated' => 'false',
        'status' => 'RECEIVED', 'date_field' => 'paymentDate', 'date_from' => '2026-01-01', 'date_to' => '2026-01-31']);
    $query = reportQuery($history);
    reportCheck($history[0]['request']->getUri()->getPath() === '/v3/payments', 'Endpoint de cobranças incorreto.');
    reportCheck($query['customer'] === 'cus_fixture' && $query['externalReference'] === 'Fatura & filial 1', 'Filtros não podem ser colocados em pathParams ou descartados.');
    reportCheck($query['anticipated'] === 'false', 'Booleano false deve ser preservado na query.');
    reportCheck($query['paymentDate[ge]'] === '2026-01-01' && $query['paymentDate[le]'] === '2026-01-31', 'Datas devem seguir campos literais da API.');
    reportCheck($page['report']['rows'][0]['value'] === '100.25' && $page['report']['environment_label'] === 'Homologação', 'Valor e ambiente incorretos.');
    $output = json_encode($page, JSON_THROW_ON_ERROR);
    reportCheck(!str_contains($output, REPORT_FIXTURE_CARD) && !str_contains($output, REPORT_FIXTURE_TOKEN) && !str_contains($output, 'creditCard'), 'Dados de cartão não podem sair da projeção do relatório.');
});

reportScenario('exportação percorre páginas menores sem saltos e produz CSV privado com texto seguro', static function (): void {
    $first = [['id' => 'ftn_1', 'date' => '2026-01-01', 'value' => 100.00, 'balance' => 100.00, 'type' => 'PAYMENT_RECEIVED', 'description' => '=HYPERLINK("https://example.invalid")'],
        ['id' => 'ftn_2', 'date' => '2026-01-01', 'value' => -1.99, 'balance' => 98.01, 'type' => 'FUTURE_FINANCIAL_TYPE', 'description' => "Tarifa; com \"aspas\"\ne linha"]];
    $second = [['id' => 'ftn_3', 'date' => '2026-01-02', 'value' => 20.15, 'balance' => 118.16, 'type' => 'CREDIT', 'description' => "\t+1+2", 'apiKey' => REPORT_FIXTURE_KEY]];
    $service = reportFixture([reportResponse(reportList($first, 0, 3, true)), reportResponse(reportList($second, 2, 3, false))], $history);
    $export = $service->export('statement', ['date_from' => '2026-01-01', 'date_to' => '2026-01-31', 'offset' => 99, 'per_page' => 25]);
    reportCheck($export->rows === 3 && count($history) === 2, 'CSV deve conter todas as páginas e ignorar a página visual.');
    reportCheck(reportQuery($history, 0)['offset'] === '0' && reportQuery($history, 1)['offset'] === '2', 'Offset deve avançar pelo número efetivamente retornado.');
    reportCheck(reportQuery($history, 1)['startDate'] === '2026-01-01' && reportQuery($history, 1)['finishDate'] === '2026-01-31', 'Filtros precisam permanecer nas demais páginas.');
    $stream = $export->stream(); $meta = stream_get_meta_data($stream);
    reportCheck((fileperms($meta['uri']) & 0777) === 0600, 'Arquivo temporário financeiro deve ter acesso restrito.');
    $csv = stream_get_contents($stream);
    reportCheck(strlen($csv) === $export->bytes && str_starts_with($csv, "\xEF\xBB\xBF"), 'CSV UTF-8/BOM e tamanho devem corresponder ao arquivo completo.');
    rewind($stream); fread($stream, 3); $headers = fgetcsv($stream, null, ';', '"', '');
    $data = []; while (($row = fgetcsv($stream, null, ';', '"', '')) !== false) { $data[] = $row; }
    reportCheck(count($data) === 3 && count($headers) === count($data[0]), 'CSV deve ser tabular, mesmo com aspas, delimitadores e quebras de linha.');
    $description = array_search('Descrição', $headers, true); $value = array_search('Valor (R$)', $headers, true);
    reportCheck(str_starts_with($data[0][$description], "'=HYPERLINK") && str_starts_with($data[2][$description], "'\t+"), 'Fórmulas e prefixos invisíveis precisam ser neutralizados.');
    reportCheck($data[1][$value] === '-1,99', 'Débito numérico não pode virar texto com apóstrofo nem perder sinal.');
    reportCheck(str_contains($csv, 'FUTURE_FINANCIAL_TYPE'), 'Tipos novos de extrato não podem interromper a exportação.');
    reportCheck(!str_contains($csv, REPORT_FIXTURE_KEY), 'Campos extras não podem expor credenciais.');
    reportCheck(preg_match('/^asaas-statement-sandbox-[0-9]{8}-[0-9]{6}\.csv$/D', $export->filename) === 1, 'Nome do arquivo deve ser gerado pelo servidor.');
    $export->close(); reportCheck(!file_exists($meta['uri']), 'Arquivo temporário deve ser removido ao encerrar o download.');
});

reportScenario('paginação visual informa o próximo offset efetivo e aceita API sem totalCount', static function (): void {
    $response = reportList([['id' => 'ftn_1'], ['id' => 'ftn_2']], 0, 3, true); unset($response['totalCount']);
    $service = reportFixture([reportResponse($response)], $history);
    $page = $service->page('statement', ['per_page' => 25]);
    reportCheck($page['report']['next_offset'] === 2 && $page['report']['total'] === null, 'Não deve inventar total nem avançar 25 quando vierem 2 itens.');
});

reportScenario('repetição entre páginas e mudanças de total impedem CSV parcial', static function (): void {
    $service = reportFixture([reportResponse(reportList([['id' => 'ftn_1']], 0, 2, true)), reportResponse(reportList([['id' => 'ftn_1']], 1, 2))], $history);
    reportThrows(static fn () => $service->export('statement'), 'repeated_page');
    $service = reportFixture([reportResponse(reportList([['id' => 'ftn_1']], 0, 2, true)), reportResponse(reportList([['id' => 'ftn_2'], ['id' => 'ftn_3']], 1, 3))], $history);
    reportThrows(static fn () => $service->export('statement'), 'report_changed');
});

reportScenario('erro HTTP na segunda página não disponibiliza resultado e não vaza resposta da API', static function (): void {
    $service = reportFixture([reportResponse(reportList([['id' => 'ftn_1']], 0, 2, true)),
        reportResponse(['errors' => [['code' => 'rate_limit', 'description' => REPORT_FIXTURE_KEY . REPORT_FIXTURE_CARD]]], 429)], $history);
    $error = reportThrows(static fn () => $service->export('statement'), 'api_rate_limit');
    reportCheck($error->httpStatus === 429 && count($history) === 2, 'Falha deve ser explícita; não iniciar outro ciclo de exportação.');
    $text = $error->getMessage() . json_encode($GLOBALS['reports_audit'], JSON_THROW_ON_ERROR);
    reportCheck(!str_contains($text, REPORT_FIXTURE_KEY) && !str_contains($text, REPORT_FIXTURE_CARD), 'Erro e auditoria não podem revelar corpo HTTP/credenciais.');
});

reportScenario('orçamento de tempo cancela o arquivo antes de retornar um download', static function (): void {
    $ticks = [0, 0, 46];
    $service = reportFixture([reportResponse(reportList([['id' => 'ftn_1']]))], $history, static function () use (&$ticks): float { return (float) (array_shift($ticks) ?? 46); });
    reportThrows(static fn () => $service->export('statement'), 'export_limit');
    reportCheck(count($history) === 1, 'Prazo ultrapassado deve parar antes de buscar outra página.');
});

reportScenario('respostas vazias, repetidas ou com paginação inconsistente não parecem relatório vazio válido', static function (): void {
    $bad = [[], ['data' => [], 'hasMore' => true], reportList([['id' => 'ftn_1'], ['id' => 'ftn_1']]),
        reportList([['id' => 'ftn_1']], 9, 10), ['data' => [['id' => 'ftn_1']], 'hasMore' => false, 'totalCount' => 4],
        ['data' => [['id' => 'ftn_1', 'value' => ['raw' => REPORT_FIXTURE_KEY]]], 'hasMore' => false, 'totalCount' => 1]];
    foreach ($bad as $response) {
        $service = reportFixture([reportResponse($response)], $history);
        reportThrows(static fn () => $service->page('statement'), 'incomplete_response');
    }
});

reportScenario('filtros inválidos são recusados antes de qualquer requisição e não aceitam URL ou método', static function (): void {
    $cases = [
        ['statement', ['endpoint' => 'https://example.invalid']], ['statement', ['date_from' => '2026-02-30']],
        ['statement', ['date_from' => '2026-03-01', 'date_to' => '2026-01-01']], ['payments', ['customer' => ['x']]],
        ['payments', ['anticipated' => '0']], ['payments', ['date_field' => 'remoteIp']], ['payments', ['status' => 'ANY']],
        ['payments', ['per_page' => 101]], ['payments', ['offset' => -1]], ['refunds', []],
        ['refunds', ['payment_id' => '../payments']], ['balance', ['date_from' => '2026-01-01']], ['bills', ['customer' => 'cus_1']],
        ['transfers', ['value' => 50]], ['not_a_report', []],
    ];
    foreach ($cases as [$report, $filters]) {
        $service = reportFixture([], $history);
        try { $service->page($report, $filters); throw new RuntimeException('Filtro deveria ser rejeitado.'); }
        catch (InvalidArgumentException) { reportCheck($history === [], 'Validação de filtros deve preceder acesso remoto.'); }
    }
});

reportScenario('contratos financeiros usam os endpoints GET reais e preservam particularidades de filtros', static function (): void {
    $cases = [
        ['balance', '/v3/finance/balance', [], ['balance' => 102.02], []],
        ['statistics', '/v3/finance/payment/statistics', ['status' => 'CONFIRMED', 'date_field' => 'estimatedCreditDate', 'date_from' => '2026-01-01'],
            ['quantity' => 3, 'value' => 120.00, 'netValue' => 118.01], ['status' => 'CONFIRMED', 'estimatedCreditDate[ge]' => '2026-01-01']],
        ['split_summary', '/v3/finance/split/statistics', [], ['income' => 12.00, 'value' => 20.00], []],
        ['splits_received', '/v3/payments/splits/received', ['paymentId' => 'pay_1', 'date_from' => '2026-01-01'], null, ['paymentId' => 'pay_1', 'creditDate[ge]' => '2026-01-01']],
        ['splits_paid', '/v3/payments/splits/paid', ['status' => 'DONE'], null, ['status' => 'DONE']],
        ['transfers', '/v3/transfers', ['date_from' => '2026-01-01', 'type' => 'ASAAS_ACCOUNT'], null, ['transferDate[ge]' => '2026-01-01', 'type' => 'ASAAS_ACCOUNT']],
        ['anticipations', '/v3/anticipations', ['payment' => 'pay_1', 'status' => 'CREDITED'], null, ['payment' => 'pay_1', 'status' => 'CREDITED']],
        ['bills', '/v3/bill', ['status' => 'PAID'], null, ['status' => 'PAID']],
        ['installments', '/v3/installments', [], null, []],
        ['subscriptions', '/v3/subscriptions', ['customer' => 'cus_1', 'includeDeleted' => 'false'], null, ['customer' => 'cus_1', 'includeDeleted' => 'false']],
        ['pix_transactions', '/v3/pix/transactions', ['type' => 'CREDIT', 'status' => 'DONE'], null, ['type' => 'CREDIT', 'status' => 'DONE']],
        ['pix_authorizations', '/v3/pix/automatic/authorizations', ['customerId' => 'cus_1', 'status' => 'ACTIVE'], null, ['customerId' => 'cus_1', 'status' => 'ACTIVE']],
        ['pix_instructions', '/v3/pix/automatic/paymentInstructions', ['authorizationId' => 'auth_1', 'customerId' => 'cus_1', 'status' => 'SCHEDULED'], null,
            ['authorizationId' => 'auth_1', 'customerId' => 'cus_1', 'status' => 'SCHEDULED']],
        ['chargebacks', '/v3/chargebacks/', ['date_from' => '2026-01-01', 'status' => 'IN_DISPUTE'], null, ['originDisputeDate[ge]' => '2026-01-01', 'status' => 'IN_DISPUTE']],
        ['service_invoices', '/v3/invoices', ['date_from' => '2026-01-01', 'date_to' => '2026-01-31'], null, ['effectiveDate[Ge]' => '2026-01-01', 'effectiveDate[Le]' => '2026-01-31']],
        ['dunnings', '/v3/paymentDunnings', ['date_from' => '2026-01-01', 'type' => 'CREDIT_BUREAU'], null, ['requestStartDate' => '2026-01-01', 'type' => 'CREDIT_BUREAU']],
        ['mobile_recharges', '/v3/mobilePhoneRecharges', [], null, []],
    ];
    foreach ($cases as [$report, $path, $filters, $response, $expected]) {
        $service = reportFixture([reportResponse($response ?? reportList([['id' => 'fixture_1']]))], $history);
        $page = $service->page($report, $filters);
        reportCheck($page['ok'] === true && $history[0]['request']->getUri()->getPath() === $path, 'Contrato do relatório ' . $report . ' deve usar endpoint oficial.');
        $query = reportQuery($history);
        foreach ($expected as $key => $value) { reportCheck(($query[$key] ?? null) === $value, 'Filtro incorreto de ' . $report . ': ' . $key); }
    }
});

reportScenario('estornos ficam restritos à cobrança e não adivinham paginação não documentada', static function (): void {
    $service = reportFixture([reportResponse(reportList([['dateCreated' => '2026-01-03', 'status' => 'DONE', 'value' => 7.10],
        ['dateCreated' => '2026-01-04', 'status' => 'PENDING', 'value' => 2.00]]))], $history);
    $page = $service->page('refunds', ['payment_id' => 'pay_1', 'per_page' => 1]);
    reportCheck($history[0]['request']->getUri()->getPath() === '/v3/payments/pay_1/refunds' && reportQuery($history) === [], 'Recurso de estorno exige pathParams e corpo vazio, sem filtros inventados.');
    reportCheck($page['report']['has_more'] === true && $page['report']['next_offset'] === 1, 'Coleção completa deve poder ser paginada na tela.');
    $service = reportFixture([reportResponse(reportList([['status' => 'DONE', 'value' => 7.10]], 0, 2, true))], $history);
    reportThrows(static fn () => $service->export('refunds', ['payment_id' => 'pay_1']), 'incomplete_response');
});

reportScenario('SDK incompatível falha explicitamente e metadados não expõem transporte ou segredos', static function (): void {
    $service = new AsaasReports(new stdClass(), new stdClass(), null, 'production');
    reportThrows(static fn () => $service->page('pix_authorizations'), 'sdk_outdated');
    $catalog = AsaasReportCatalog::publicDefinitions();
    reportCheck(count($catalog) === 20, 'Catálogo precisa incluir os 20 conjuntos financeiros implementados.');
    reportCheck(!str_contains(json_encode($catalog, JSON_THROW_ON_ERROR), '"method"'), 'Cliente não escolhe métodos de transporte.');
    foreach (["=1", " +1", "\t-1", "\n@SUM(1)", "\u{00A0}=1", "\u{FEFF}=1"] as $value) {
        reportCheck(str_starts_with(AsaasReports::csvText($value), "'"), 'CSV textual precisa neutralizar prefixos de fórmula.');
    }
});

echo $scenarios . ' cenários; ' . $assertions . ' verificações. Nenhuma API ou cobrança real foi acessada.' . PHP_EOL;
