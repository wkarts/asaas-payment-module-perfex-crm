<?php

declare(strict_types=1);

require __DIR__ . '/fixtures/automatic-pix-fixture.php';
$cases=0; $assertions=0; $failures=0;
function pixAssert(bool $test,string $message):void { ++$GLOBALS['assertions']; if(!$test)throw new RuntimeException($message); }
function pixCase(string $name,callable $case):void
{
    ++$GLOBALS['cases'];
    try{$case();echo 'PASS '.$name.PHP_EOL;}
    catch(Throwable $e){++$GLOBALS['failures'];echo 'FAIL '.$name.': '.$e->getMessage().' @ '.$e->getFile().':'.$e->getLine().PHP_EOL;}
}
function pixActivate(object $service,object $store,object $gateway,object $invoice):array
{
    pixAssert($service->enroll($invoice,pixConsent($service,$invoice))['ok'],'Fixture enrollment must work.');
    $profile=pixProfile();pixInitialPayment($gateway,$profile);$gateway->api->remoteAuthorizations[$profile['remote_id']]['status']='ACTIVE';
    pixAssert($service->refresh($invoice)['state']==='confirmed','Initial settlement must precede a cycle.');
    return pixProfile();
}
pixCase('Real additive schema, readiness and shared profile lock',function():void{
    [$service,$store,$gateway,$invoice]=pixFixture();$db=get_instance()->db;
    pixAssert($store->ready(),'All real readiness checks run.');
    $before=count(array_filter($db->queries,fn($sql)=>str_starts_with($sql,'CREATE TABLE')));
    asaas_automatic_pix_schema_install($db);
    pixAssert($before===count(array_filter($db->queries,fn($sql)=>str_starts_with($sql,'CREATE TABLE'))),'Reinstall must not recreate tables.');
    $scope=asaas_automatic_pix_scope($invoice,$gateway);$lock=$store->lock(7,$scope['key']);
    $card=new \Asaas\AsaasRecurringStore($db);$other=$card->lock(7,$scope['key']);
    pixAssert($lock===$other,'Pix and recurring card must serialize on the same customer/account lock.');
    $card->unlock($other);$store->assertLock($lock);$store->unlock($lock);
    $db->query('DROP TABLE tblasaas_pix_jobs');
    pixAssert(!$store->ready()&&!$service->page($invoice)['can_enroll'],'Missing migration must fail closed.');
});
pixCase('Read model and rejected consent do not issue financial requests',function():void{
    [$service,$store,$gateway,$invoice]=pixFixture();
    pixAssert($service->page($invoice)['can_enroll']&&$gateway->api->calls===[],'Page rendering is local only.');
    foreach([['accepted'=>false],['contact_id'=>10],['contact_id'=>11],['frequency'=>'DAILY'],['terms_version'=>'old'],['quote'=>str_repeat('0',64)]]as$change){
        pixAssert(!$service->enroll($invoice,pixConsent($service,$invoice,$change))['ok'],'Invalid consent/contact/frequency/quote must be rejected.');
    }
    pixAssert($gateway->api->calls===[]&&pixProfile()===[],'Rejection must precede any authorization API call.');
});
pixCase('Created authorization is not active and QR is encrypted at rest',function():void{
    [$service,$store,$gateway,$invoice]=pixFixture();$result=$service->enroll($invoice,pixConsent($service,$invoice));$profile=pixProfile();
    pixAssert($result['ok']&&$profile['state']==='created'&&$store->job(42)['state']==='waiting','Only CREATED is persisted after issuing QR.');
    $raw=$gateway->api->remoteAuthorizations[$profile['remote_id']]['payload'];
    pixAssert(!str_contains($profile['qrcode_ciphertext'],$raw)&&$service->page($invoice)['payload']===$raw,'Only the owned read model decrypts QR.');
    pixAssert(!str_contains(json_encode($profile),'fictional-key'),'API credentials must never enter mandate storage.');
    pixAssert(!$service->authorizesNewInvoice((array)$invoice)&&asaas_automatic_pix_invoice_blocks(42),'Created authorization cannot debit another invoice; initial one is guarded.');
    pixAssert(!$service->enroll($invoice,pixConsent($service,$invoice))['ok']&&count(array_filter($gateway->api->calls,fn($v)=>$v==='POST authorization'))===1,'Repeated form cannot create a second authorization.');
});
pixCase('Existing boleto is removed and confirmed before exposing the new QR',function():void{
    [$service,$store,$gateway,$invoice]=pixFixture();
    $gateway->api->remotePayments['pay_previous']=['id'=>'pay_previous','customer'=>'cus_pix_fixture','status'=>'OVERDUE','billingType'=>'BOLETO','value'=>'100.00','externalReference'=>$invoice->hash];
    $gateway->api->beforeCreate=function(array$payload):void{
        $reader=new PDO('sqlite:'.get_instance()->db->path);$state=$reader->query('SELECT state FROM tblasaas_pix_jobs')->fetchColumn();
        pixAssert($state==='creating'&&!get_instance()->db->conn_id->inTransaction(),'Journal must be visible from an independent connection before POST.');
        pixAssert($payload['paymentCreationMode']==='MANUAL'&&$payload['retryPolicy']==='NOT_ALLOWED','CRM controls cycles; no unrequested extraday retries.');
    };
    pixAssert($service->enroll($invoice,pixConsent($service,$invoice))['ok'],'Existing boleto conversion should succeed.');
    pixAssert($gateway->api->calls===['GET payment','DELETE payment','GET payment','POST authorization'],'DELETE is verified before QR creation.');
    pixAssert(asaas_lifecycle_cached_payment(asaas_lifecycle_map(42))['_asaas_lifecycle']['state']==='deleted','Old link records the confirmed retirement.');
});
pixCase('Already paid or divergent boleto cannot be replaced',function():void{
    foreach([['status'=>'RECEIVED'],['value'=>'101.00'],['billingType'=>'CREDIT_CARD']]as$change){
        [$service,$store,$gateway,$invoice]=pixFixture();
        $gateway->api->remotePayments['pay_previous']=array_replace(['id'=>'pay_previous','customer'=>'cus_pix_fixture','status'=>'PENDING','billingType'=>'BOLETO','value'=>'100.00','externalReference'=>$invoice->hash],$change);
        pixAssert(!$service->enroll($invoice,pixConsent($service,$invoice))['ok'],'Divergent remote debt must fail before DELETE/POST.');
        pixAssert($gateway->api->calls===[],'Do not mutate an already received or divergent charge.');
    }
});
pixCase('Unknown authorization response recovers its unique contract without repeating POST',function():void{
    [$service,$store,$gateway,$invoice]=pixFixture();$gateway->api->authorizationError=new \Asaas\AsaasAutomaticPixRequestException('uncertain',0,true);
    pixAssert($service->enroll($invoice,pixConsent($service,$invoice))['state']==='uncertain','Lost response must remain guarded.');
    $gateway->api->authorizationError=null;$service->refresh($invoice);$profile=pixProfile();
    pixAssert($profile['state']==='created'&&!empty($profile['remote_id']),'GET contract match recovers the created authorization.');
    pixAssert(count(array_filter($gateway->api->calls,fn($v)=>$v==='POST authorization'))===1,'Recovery cannot repeat POST.');
    pixAssert(asaas_automatic_pix_invoice_blocks(42),'Still no receipt: invoice remains protected.');
});
pixCase('Uncertain deletion is recovered without creating or deleting again',function():void{
    [$service,$store,$gateway,$invoice]=pixFixture();
    $gateway->api->remotePayments['pay_previous']=['id'=>'pay_previous','customer'=>'cus_pix_fixture','status'=>'PENDING','billingType'=>'BOLETO','value'=>'100.00','externalReference'=>$invoice->hash];
    $gateway->api->deleteError=new \Asaas\AsaasAutomaticPixRequestException('uncertain',0,true);
    pixAssert($service->enroll($invoice,pixConsent($service,$invoice))['state']==='uncertain','Unknown DELETE must block further payment.');
    $gateway->api->deleteError=null;$result=$service->processInvoice(42,true);
    pixAssert($result['state']==='failed'&&!asaas_automatic_pix_invoice_blocks(42),'Known deleted debt with no authorization POST safely releases fallback.');
    pixAssert(count(array_filter($gateway->api->calls,fn($v)=>$v==='DELETE payment'))===1&&!in_array('POST authorization',$gateway->api->calls,true),'No destructive retry or new QR during recovery.');
});
pixCase('First payment recovers without a webhook and does not infer bank authorization',function():void{
    [$service,$store,$gateway,$invoice]=pixFixture();$service->enroll($invoice,pixConsent($service,$invoice));$profile=pixProfile();$payment=pixInitialPayment($gateway,$profile);
    $result=$service->refresh($invoice);
    pixAssert($result['state']==='confirmed'&&$store->job(42)['payment_id']===$payment['id'],'Exact Pix transaction links the initial payment.');
    pixAssert(pixProfile()['state']==='created','A received first Pix does not turn CREATED into ACTIVE.');
    $service->refresh($invoice);$service->processInvoice(42,true);
    pixAssert(count(get_instance()->db->query('SELECT * FROM tblinvoicepaymentrecords')->result_array())===1,'Retrying GET reconciliation cannot duplicate receipt.');
    pixAssert($service->matchesPayment($invoice,$payment),'Initial payment without externalReference matches only its verified immutable job.');
});
pixCase('Same customer and value with a different Pix correlation never settles invoice',function():void{
    [$service,$store,$gateway,$invoice]=pixFixture();$service->enroll($invoice,pixConsent($service,$invoice));$payment=pixInitialPayment($gateway,pixProfile());
    $gateway->api->remoteTransactions[$payment['pixTransaction']]['conciliationIdentifier']='txid_unrelated';
    $service->refresh($invoice);
    pixAssert(get_instance()->invoices_model->get(42)->status===1&&empty($store->job(42)['payment_id']),'Value/customer are not a matching heuristic.');
    pixAssert($service->resolvePayment($payment)===null,'Webhook resolution rejects the unrelated Pix.');
});
pixCase('Verified webhook mapping leaves the native receipt writer responsible',function():void{
    [$service,$store,$gateway,$invoice]=pixFixture();$service->enroll($invoice,pixConsent($service,$invoice));$payment=pixInitialPayment($gateway,pixProfile());
    $found=$service->resolvePayment($payment);
    pixAssert($found&&$found->id===42&&$store->job(42)['payment_id']===$payment['id'],'Resolver establishes exact invoice identity.');
    pixAssert(get_instance()->db->query('SELECT * FROM tblinvoicepaymentrecords')->result_array()===[],'Resolver must not write a receipt before native webhook accounting.');
    pixAssert(array_sum(get_instance()->db->locks)===0,'Resolver releases its locks before the native webhook acquires one.');
});
pixCase('First webhook mapping rejects a divergent event despite a correct GET result',function():void{
    [$service,$store,$gateway,$invoice]=pixFixture();$service->enroll($invoice,pixConsent($service,$invoice));$payment=pixInitialPayment($gateway,pixProfile());
    foreach([['value'=>50],['customer'=>'cus_other'],['installment'=>'group_other'],['subscription'=>'sub_other'],['externalReference'=>'other-invoice'],['deleted'=>true]]as$change){
        $caught=false;
        try{$service->resolvePayment(array_replace($payment,$change));}catch(RuntimeException$e){$caught=$e->getMessage()==='charge_mismatch';}
        pixAssert($caught&&empty($store->job(42)['payment_id']),'The event itself must match the same invoice, customer, amount and standalone payment.');
    }
    pixAssert(get_instance()->db->query('SELECT * FROM tblinvoicepaymentrecords')->result_array()===[]&&array_sum(get_instance()->db->locks)===0,'Rejected events create no receipt and release all locks.');
    pixAssert($service->resolvePayment($payment)->id===42,'A later valid event can still establish the association.');
    pixAssert(!$service->matchesPayment($invoice,array_replace($payment,['subscription'=>'sub_other'])),'Known linkage also rejects grouped payment payloads.');
});
pixCase('Cancel preserves QR/payment guard until authoritative terminal state and complete expired scan',function():void{
    [$service,$store,$gateway,$invoice]=pixFixture();$service->enroll($invoice,pixConsent($service,$invoice));
    pixAssert($service->cancel($invoice)['state']==='cancelled','DELETE alone is followed by GET for authoritative status.');
    $service->refresh($invoice);
    pixAssert(asaas_automatic_pix_invoice_blocks(42),'Unexpired initial QR must not race a fallback payment.');
    $GLOBALS['pix_now']='2026-11-09 10:20:00';$service->refresh($invoice);
    pixAssert(!asaas_automatic_pix_invoice_blocks(42)&&$store->job(42)['state']==='cancelled','Only expired and checked QR releases fallback.');
    $id=pixProfile()['remote_id'];$gateway->api->remoteAuthorizations[$id]['status']='ACTIVE';$service->refresh($invoice);
    pixAssert(pixProfile()['state']==='cancelled','A stale ACTIVE response cannot resurrect a cancelled mandate.');
});
pixCase('Lost cancel response performs GET recovery, never another DELETE',function():void{
    [$service,$store,$gateway,$invoice]=pixFixture();$service->enroll($invoice,pixConsent($service,$invoice));
    $gateway->api->cancelError=new \Asaas\AsaasAutomaticPixRequestException('uncertain',0,true);$service->cancel($invoice);$gateway->api->cancelError=null;
    pixAssert(pixProfile()['state']==='cancelling','Local cancel intent blocks new cycles.');
    $service->cancel($invoice);
    pixAssert(pixProfile()['state']==='cancelled'&&count(array_filter($gateway->api->calls,fn($v)=>$v==='DELETE authorization'))===1,'Second action only queries the pending cancellation.');
});
pixCase('New cycle respects window and DONE instruction never records payment',function():void{
    [$service,$store,$gateway,$invoice]=pixFixture();$profile=pixActivate($service,$store,$gateway,$invoice);
    $future=get_instance()->db->invoice(43,['duedate'=>'2026-12-10']);pixAssert($service->enqueue(43),'New invoice is queued without API work.');
    pixAssert($service->processInvoice(43)['state']==='queued','More than ten business days waits.');
    $GLOBALS['pix_now']='2026-12-07 10:00:00';$result=$service->processInvoice(43);$job=$store->job(43);
    pixAssert($result['state']==='waiting'&&!empty($job['payment_id']),'Inside the window, one charge is created.');
    $paymentId=$job['payment_id'];$gateway->api->remoteInstructions[$paymentId][0]['status']='DONE';$service->processInvoice(43,true);
    pixAssert(get_instance()->invoices_model->get(43)->status===1,'DONE is not settlement proof.');
    $gateway->api->remotePayments[$paymentId]['status']='CONFIRMED';$service->processInvoice(43,true);$service->processInvoice(43,true);
    pixAssert(get_instance()->invoices_model->get(43)->status===2&&count(get_instance()->db->query('SELECT * FROM tblinvoicepaymentrecords')->result_array())===2,'Only payment confirmation writes the one new receipt.');
    pixAssert(count(array_filter($gateway->api->calls,fn($v)=>$v==='POST payment'))===1,'A cycle can never be reissued during reconciliation.');
});
pixCase('Payment timeout recovers remote charge by hash and instruction mandate',function():void{
    [$service,$store,$gateway,$invoice]=pixFixture();pixActivate($service,$store,$gateway,$invoice);
    get_instance()->db->invoice(43,['duedate'=>'2026-12-10']);$service->enqueue(43);$GLOBALS['pix_now']='2026-12-07 10:00:00';
    $gateway->api->paymentError=new \Asaas\AsaasAutomaticPixRequestException('uncertain',0,true);$service->processInvoice(43);
    pixAssert($store->job(43)['state']==='uncertain','Uncertain cycle creation stays guarded.');$gateway->api->paymentError=null;
    foreach($gateway->api->remotePayments as&$p){if(!empty($p['externalReference']))unset($p['pixAutomaticAuthorizationId']);}unset($p);
    $service->processInvoice(43,true);
    pixAssert($store->job(43)['state']==='waiting'&&!empty($store->job(43)['payment_id']),'Instruction validates mandate when payment omits the authorization field.');
    pixAssert(count(array_filter($gateway->api->calls,fn($v)=>$v==='POST payment'))===1,'Recovery does not repeat payment POST.');
});
pixCase('Unpaid refusal allows fallback but retains active mandate for future cycles',function():void{
    [$service,$store,$gateway,$invoice]=pixFixture();pixActivate($service,$store,$gateway,$invoice);
    get_instance()->db->invoice(43,['duedate'=>'2026-12-10']);$service->enqueue(43);$GLOBALS['pix_now']='2026-12-07 10:00:00';$service->processInvoice(43);
    $id=$store->job(43)['payment_id'];$gateway->api->remoteInstructions[$id][0]['status']='REFUSED';$service->processInvoice(43,true);
    pixAssert($store->job(43)['state']==='failed'&&!asaas_automatic_pix_invoice_blocks(43)&&pixProfile()['state']==='active','Instruction failure differs from mandate cancellation.');
    pixAssert(get_instance()->invoices_model->get(43)->status===1,'Refusal never pays the invoice.');
});
pixCase('Old debt, duplicate cycle and insufficient lead time are not automatically charged',function():void{
    [$service,$store,$gateway,$invoice]=pixFixture();get_instance()->db->invoice(10);pixActivate($service,$store,$gateway,$invoice);
    pixAssert(!$service->enqueue(10),'Consent excludes old invoice ids.');
    get_instance()->db->invoice(43,['duedate'=>'2026-12-10']);get_instance()->db->invoice(44,['duedate'=>'2026-12-12']);
    $service->enqueue(43);$service->enqueue(44);pixAssert($store->job(44)['state']==='skipped','Only one invoice can claim a cycle.');
    $GLOBALS['pix_now']='2026-12-09 10:00:00';pixAssert($service->processInvoice(43)['error_code']==='outside_window','Too late stays available for one-time payment.');
    pixAssert(!in_array('POST payment',$gateway->api->calls,true),'No late debit request is sent.');
});
pixCase('Account change and missing local receipt fail closed',function():void{
    [$service,$store,$gateway,$invoice]=pixFixture();$service->enroll($invoice,pixConsent($service,$invoice));$payment=pixInitialPayment($gateway,pixProfile());
    $gateway->provider->environment='production';$before=count($gateway->api->calls);$service->processInvoice(42,true);
    pixAssert(count($gateway->api->calls)===$before,'Old sandbox job cannot query or debit the production account.');
    $gateway->provider->environment='sandbox';get_instance()->db->invoice(42,['status'=>2,'balance'=>'0.00']);$result=$service->processInvoice(42,true);
    pixAssert($result['error_code']==='receipt_conflict'&&asaas_automatic_pix_invoice_blocks(42),'Manual balance change cannot silently absorb an extra Pix receipt.');
});
pixCase('Pagination resumes initial payment recovery beyond the first ten charges',function():void{
    [$service,$store,$gateway,$invoice]=pixFixture();$service->enroll($invoice,pixConsent($service,$invoice));$profile=pixProfile();
    for($i=0;$i<12;$i++){$p=pixInitialPayment($gateway,$profile,'RECEIVED','pay_other_'.$i);$gateway->api->remoteTransactions[$p['pixTransaction']]['conciliationIdentifier']='other_'.$i;}
    $target=pixInitialPayment($gateway,$profile);$service->processInvoice(42,true);
    pixAssert((int)pixProfile()['payment_scan_offset']===10&&get_instance()->invoices_model->get(42)->status===1,'First page must save progress without selecting a same-value receipt.');
    $service->processInvoice(42,true);
    pixAssert($store->job(42)['payment_id']===$target['id']&&get_instance()->invoices_model->get(42)->status===2,'Next page recovers the exact missed first payment.');
});
pixCase('Brazilian business calendar and configured holiday affect scheduling',function():void{
    $calendar=new \Asaas\AsaasAutomaticPixCalendar(['2026-11-11']);
    pixAssert($calendar->businessDays('2026-11-09','2026-11-12')===2,'Additional holiday removes one working day.');
    pixAssert($calendar->businessDays('2026-11-19','2026-11-23')===1,'20 November and weekend are excluded.');
    pixAssert(!$calendar->isBusinessDay($calendar->date('2026-04-03')),'Good Friday is a banking holiday.');
    pixAssert(!$calendar->isBusinessDay($calendar->date('2026-02-17')),'Carnival is excluded.');
});
pixCase('Successful POST followed by rejected GET keeps invoice blocked',function():void{
    [$service,$store,$gateway,$invoice]=pixFixture();pixActivate($service,$store,$gateway,$invoice);
    get_instance()->db->invoice(43,['duedate'=>'2026-12-10']);$service->enqueue(43);$GLOBALS['pix_now']='2026-12-07 10:00:00';
    $gateway->api->omitPaymentAuthorizationId=true;$gateway->api->instructionsReadError=new \Asaas\AsaasAutomaticPixRequestException('request_rejected',404,true);
    $service->processInvoice(43);
    pixAssert($store->job(43)['state']==='uncertain'&&asaas_automatic_pix_invoice_blocks(43),'A 404 in the GET after a successful POST is not a rejected payment.');
    $gateway->api->instructionsReadError=null;$service->processInvoice(43,true);
    pixAssert($store->job(43)['state']==='waiting'&&count(array_filter($gateway->api->calls,fn($v)=>$v==='POST payment'))===1,'GET-only follow-up recovers the scheduled payment.');
});
pixCase('Successful cancellation followed by rejected GET preserves cancellation intent',function():void{
    [$service,$store,$gateway,$invoice]=pixFixture();$service->enroll($invoice,pixConsent($service,$invoice));
    $gateway->api->authorizationReadError=new \Asaas\AsaasAutomaticPixRequestException('request_rejected',404,true);$service->cancel($invoice);
    pixAssert(pixProfile()['state']==='cancelling'&&!empty(pixProfile()['cancel_requested_at']),'A failed follow-up GET cannot undo a successful DELETE intent.');
    $gateway->api->authorizationReadError=null;$service->refresh($invoice);
    pixAssert(pixProfile()['state']==='cancelled'&&count(array_filter($gateway->api->calls,fn($v)=>$v==='DELETE authorization'))===1,'Cancellation result is recovered without another DELETE.');
});
pixCase('Existing receipt with wrong amount never marks Pix job confirmed',function():void{
    [$service,$store,$gateway,$invoice]=pixFixture();$service->enroll($invoice,pixConsent($service,$invoice));$payment=pixInitialPayment($gateway,pixProfile());
    get_instance()->db->insert('tblinvoicepaymentrecords',['invoiceid'=>42,'transactionid'=>$payment['id'],'amount'=>'50.00']);
    $result=$service->processInvoice(42,true);
    pixAssert($result['error_code']==='receipt_conflict'&&$store->job(42)['state']==='uncertain','Transaction id alone cannot conceal a wrong local amount.');
});
pixCase('Preparation recovery cannot credit a smaller remote payment',function():void{
    [$service,$store,$gateway,$invoice]=pixFixture();
    $gateway->api->remotePayments['pay_previous']=['id'=>'pay_previous','customer'=>'cus_pix_fixture','status'=>'PENDING','billingType'=>'BOLETO','value'=>'100.00','externalReference'=>$invoice->hash];
    $gateway->api->deleteError=new \Asaas\AsaasAutomaticPixRequestException('uncertain',0,true);$service->enroll($invoice,pixConsent($service,$invoice));
    $gateway->api->deleteError=null;$gateway->api->remotePayments['pay_previous']=array_replace($gateway->api->remotePayments['pay_previous'],['deleted'=>false,'status'=>'RECEIVED','value'=>'50.00']);
    $result=$service->processInvoice(42,true);
    pixAssert($result['error_code']==='receipt_conflict'&&get_instance()->db->query('SELECT * FROM tblinvoicepaymentrecords')->result_array()===[],'A remotely changed paid amount requires review instead of full settlement.');
});
pixCase('Contradictory terminal scan response never releases fallback',function():void{
    [$service,$store,$gateway,$invoice]=pixFixture();$service->enroll($invoice,pixConsent($service,$invoice));$service->cancel($invoice);
    $GLOBALS['pix_now']='2026-11-09 10:20:00';$gateway->api->paymentsPageOverride=['data'=>[],'hasMore'=>false,'offset'=>0,'limit'=>10,'totalCount'=>25];
    $result=$service->refresh($invoice);
    pixAssert($result['state']==='uncertain'&&asaas_automatic_pix_invoice_blocks(42),'Contradictory empty page does not prove no first payment exists.');
});
pixCase('Terminal expiry starts a fresh scan before considering fallback',function():void{
    [$service,$store,$gateway,$invoice]=pixFixture();$service->enroll($invoice,pixConsent($service,$invoice));$profile=pixProfile();
    for($i=0;$i<12;$i++){$p=pixInitialPayment($gateway,$profile,'RECEIVED','pay_other_'.$i);$gateway->api->remoteTransactions[$p['pixTransaction']]['conciliationIdentifier']='other_'.$i;}
    $service->processInvoice(42,true);pixAssert((int)pixProfile()['payment_scan_offset']===10,'Active QR scan has already advanced.');
    $target=pixInitialPayment($gateway,$profile);$gateway->api->remotePayments=[$target['id']=>$target]+$gateway->api->remotePayments;
    $gateway->api->remoteAuthorizations[$profile['remote_id']]['status']='REFUSED';$GLOBALS['pix_now']='2026-11-09 10:20:00';$service->refresh($invoice);
    pixAssert($store->job(42)['state']==='confirmed'&&get_instance()->invoices_model->get(42)->status===2,'A late first payment inserted before the old cursor is recovered from the fresh scan.');
});
pixCase('Active card mandate prevents Pix enrollment and sent Pix prevents card enrollment',function():void{
    [$service,$store,$gateway,$invoice]=pixFixture();$scope=asaas_automatic_pix_scope($invoice,$gateway);$now=date('Y-m-d H:i:s');
    $card=new \Asaas\AsaasRecurringStore(get_instance()->db);
    $profile=$card->saveAuthorization(['client_id'=>7,'scope_key'=>$scope['key'],'environment'=>'sandbox','company_id'=>0,'customer_id'=>'cus_pix_fixture',
        'revision'=>1,'state'=>'active','token_ciphertext'=>'fictional-sealed-token','contact_id'=>9,'consent_version'=>'fixture','authorized_at'=>$now,
        'cutoff_invoice_id'=>42,'pricing_policy_hash'=>hash('sha256','fixture'),'updated_at'=>$now],null);
    pixAssert($service->enroll($invoice,pixConsent($service,$invoice))['error_code']==='other_mandate'&&$gateway->api->calls===[],'No Pix POST with an active card mandate.');
    $card->stopAuthorization($profile,'revoked','revoked');$service->enroll($invoice,pixConsent($service,$invoice));
    pixAssert(asaas_automatic_pix_client_blocks($invoice),'The reciprocal guard blocks a second automatic method.');
});
pixCase('Backfill cursor advances past old credential scopes',function():void{
    [$service,$store,$gateway,$invoice]=pixFixture();pixActivate($service,$store,$gateway,$invoice);
    for($i=43;$i<96;$i++){get_instance()->db->invoice($i,['duedate'=>'2026-12-10']);}
    $gateway->provider->key='changed-fictional-key';$service->work();
    $first=(int)get_instance()->db->query("SELECT value FROM tbloptions WHERE name='asaas_pix_scan_cursor'")->row_array()['value'];
    $service->work();$second=(int)get_instance()->db->query("SELECT value FROM tbloptions WHERE name='asaas_pix_scan_cursor'")->row_array()['value'];
    pixAssert($first===82&&$second===95,'Ineligible early records cannot monopolize every batch.');
    pixAssert(count(array_filter($gateway->api->calls,fn($v)=>$v==='POST payment'))===0,'Cursor advancement cannot charge an invoice under the wrong credentials.');
});
pixCase('A successful writer return cannot replace a persisted receipt',function():void{
    [$service,$store,$gateway,$invoice]=pixFixture();$service->enroll($invoice,pixConsent($service,$invoice));pixInitialPayment($gateway,pixProfile());
    $gateway->pretendReceipt=true;$result=$service->processInvoice(42,true);
    pixAssert($result['error_code']==='receipt_pending'&&$store->job(42)['state']==='uncertain','No local receipt means no confirmation, even if the writer returned true.');
    $gateway->pretendReceipt=false;$service->processInvoice(42,true);
    pixAssert($store->job(42)['state']==='confirmed','A later GET recovery confirms the real persisted receipt.');
});
asaas_receipt_unlock_all();
echo $cases.' scenarios; '.$assertions.' assertions; '.$failures.' failures'.PHP_EOL;
exit($failures?1:0);
