<?php

declare(strict_types=1);

/** Disposable MariaDB only: verifies real DDL, uniqueness, CAS and shared card/Pix locks. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('BASEPATH', __DIR__);
error_reporting(E_ALL);
set_error_handler(static function(int $severity,string $message,string $file,int $line):bool {
    if (!(error_reporting() & $severity)) { return false; }
    throw new ErrorException($message,0,$severity,$file,$line);
});
$dsn=getenv('ASAAS_MONITOR_TEST_DSN');
if (!is_string($dsn)||!str_starts_with($dsn,'mysql:')) { fwrite(STDERR,"Defina ASAAS_MONITOR_TEST_DSN para o banco descartável.\n"); exit(1); }
$connect=static fn():PDO=>new PDO($dsn,(string)getenv('ASAAS_MONITOR_TEST_USER'),(string)getenv('ASAAS_MONITOR_TEST_PASSWORD'),
    [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
$GLOBALS['pix_mysql_prefix']='asaas_px_'.bin2hex(random_bytes(5)).'_';
function db_prefix():string{return $GLOBALS['pix_mysql_prefix'];}
function get_option(string$name){return false;}
function &get_instance():object{return $GLOBALS['pix_mysql_ci'];}
final class PixMysqlResult
{
    public function __construct(private array$rows){}
    public function result_array():array{return $this->rows;}
    public function row_array():array{return $this->rows[0]??[];}
}
final class PixMysqlDb
{
    public string$dbdriver='pdo';public string$subdriver='mysql';public string$hostname='fixture-local';public string$database='pix-fixture';
    public bool$db_debug=true;public int$_trans_depth=0;public array$queries=[];private int$affected=0;
    public function __construct(public PDO$conn_id){}
    public function query(string$sql,array$bind=[]):PixMysqlResult{
        $this->queries[]=$sql;$statement=$this->conn_id->prepare($sql);$statement->execute($bind);$this->affected=$statement->rowCount();
        return new PixMysqlResult($statement->columnCount()?$statement->fetchAll(PDO::FETCH_ASSOC):[]);
    }
    public function affected_rows():int{return $this->affected;}
    public function table_exists(string$table):bool{return $this->query('SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?',[$table])->row_array()!==[];}
}
$db=new PixMysqlDb($connect());$otherDb=new PixMysqlDb($connect());$GLOBALS['pix_mysql_ci']=(object)['db'=>$db];
require_once dirname(__DIR__).'/helpers/asaas_recurring_schema_helper.php';
require_once dirname(__DIR__).'/helpers/asaas_automatic_pix_schema_helper.php';
require_once dirname(__DIR__).'/libraries/Asaas/AsaasAutomaticPixStore.php';
$assertions=0;$assert=static function(bool$condition,string$message)use(&$assertions):void{++$assertions;if(!$condition)throw new RuntimeException($message);};
$tables=array_map(static fn($s):string=>db_prefix().$s,['asaas_pix_jobs','asaas_pix_authorizations','asaas_recurring_jobs','asaas_recurring_authorizations','options','contacts','invoices','invoicepaymentrecords']);
$lock=null;
try{
    $db->query('CREATE TABLE `'.db_prefix().'options` (name VARCHAR(100) PRIMARY KEY,value TEXT) ENGINE=InnoDB');
    $db->query('CREATE TABLE `'.db_prefix().'contacts` (id INT PRIMARY KEY,userid INT,active INT) ENGINE=InnoDB');
    $db->query('CREATE TABLE `'.db_prefix().'invoices` (id INT PRIMARY KEY,clientid INT,status INT,allowed_payment_modes TEXT) ENGINE=InnoDB');
    $db->query('CREATE TABLE `'.db_prefix().'invoicepaymentrecords` (id INT PRIMARY KEY,invoiceid INT,transactionid VARCHAR(128),amount DECIMAL(15,2)) ENGINE=InnoDB');
    $db->query('INSERT INTO `'.db_prefix().'options` VALUES (?,?),(?,?)',['paymentmethod_asaas_active','1','paymentmethod_asaas_automatic_pix_active','1']);
    $db->query('INSERT INTO `'.db_prefix().'contacts` VALUES (9,7,1),(10,8,1),(11,7,0)');
    $store=new \Asaas\AsaasAutomaticPixStore($db);
    $assert(!$store->ready(),'Missing schema must be detected before scheduling.');
    asaas_recurring_schema_install($db);asaas_automatic_pix_schema_install($db);
    $createCount=count(array_filter($db->queries,static fn($q):bool=>str_starts_with($q,'CREATE TABLE')));
    asaas_automatic_pix_schema_install($db);
    $assert($createCount===count(array_filter($db->queries,static fn($q):bool=>str_starts_with($q,'CREATE TABLE'))),'Repeated migration must be additive/idempotent.');
    $assert($store->ready()&&$store->enabled(),'Real tables and driver support feature readiness.');
    $assert($store->contactBelongs(9,7)&&!$store->contactBelongs(10,7)&&!$store->contactBelongs(11,7),'Consent owner must be an active contact of this client.');
    $scope=hash('sha256',db_prefix());$lock=$store->lock(7,$scope);$store->assertLock($lock);
    $query=$otherDb->query('SELECT GET_LOCK(?,0) AS acquired',[$lock])->row_array();
    $assert((int)$query['acquired']===0,'An independent DB connection cannot acquire the customer mandate lock.');
    $cardStore=new \Asaas\AsaasRecurringStore($db);$cardLock=$cardStore->lock(7,$scope);
    $assert($cardLock===$lock,'Card and Pix must share the exact same lock identity.');$cardStore->unlock($cardLock);$store->assertLock($lock);
    $now=date('Y-m-d H:i:s');
    $profile=$store->insertAuthorization(['client_id'=>7,'scope_key'=>$scope,'environment'=>'sandbox','company_id'=>0,'customer_id'=>'cus_mysql_fixture',
        'initial_invoice_id'=>42,'initial_invoice_hash'=>'hash-mysql-fixture','initial_amount_cents'=>10000,'cutoff_invoice_id'=>42,
        'contract_id'=>'pf'.bin2hex(random_bytes(16)),'state'=>'creating','frequency'=>'MONTHLY','start_date'=>'2026-11-09','contact_id'=>9,
        'consent_version'=>'test-v1','authorized_at'=>$now,'created_at'=>$now,'updated_at'=>$now]);
    $otherStore=new \Asaas\AsaasAutomaticPixStore($otherDb);
    $assert($otherStore->authorizationById((int)$profile['id'])['state']==='creating','The journal must be durable before dispatch.');
    $assert(!$store->updateAuthorization((int)$profile['id'],['active'],['state'=>'cancelled']),'Stale state cannot overwrite current authorization.');
    $assert($store->updateAuthorization((int)$profile['id'],['creating'],['state'=>'created','remote_id'=>'remote_mysql_fixture','conciliation_identifier'=>'txid_mysql_fixture']),'Expected-state CAS updates exactly one record.');
    $assert($store->clientBlocks(7,$scope)&&!$store->clientBlocks(8,$scope),'Only this owner is blocked by a pending mandate.');
    $job=['invoice_id'=>42,'invoice_hash'=>'hash-mysql-fixture','client_id'=>7,'scope_key'=>$scope,'authorization_id'=>$profile['id'],'kind'=>'initial',
        'state'=>'waiting','phase'=>'initial_qr','principal_cents'=>10000,'due_date'=>'2026-11-09','cycle_key'=>'m0','created_at'=>$now,'updated_at'=>$now];
    $assert($store->insertJob($job),'Initial job insert succeeds.');
    $assert(!$store->insertJob($job),'A repeated invoice job is never replaced.');
    $assert(!$store->insertJob(array_replace($job,['invoice_id'=>43,'invoice_hash'=>'other-hash'])),'Unique authorization/cycle prevents two automatic debits in one cycle.');
    $assert($store->updateJob(42,['waiting'],['payment_id'=>'pay_mysql_fixture']),'Verified payment id can be persisted.');
    $assert($store->jobByPayment('pay_mysql_fixture')['invoice_hash']==='hash-mysql-fixture','Strict payment index preserves invoice identity.');
    $assert(!$store->updateJob(42,['queued'],['state'=>'creating']),'A worker cannot resubmit an already sent job.');
    $store->unlock($lock);$lock=null;
    $secondLock=$otherStore->lock(7,$scope);$otherStore->assertLock($secondLock);$otherStore->unlock($secondLock);
    $assert(true,'The same lock becomes available after explicit release.');
    $db->query('UPDATE `'.db_prefix().'options` SET value=? WHERE name=?',['0','paymentmethod_asaas_automatic_pix_active']);
    $assert(!$store->enabled(),'A worker must observe gateway disablement on fresh DB read.');
    echo 'PASS Pix Automático MySQL: '.$assertions.' assertions'.PHP_EOL;
}finally{
    if($lock!==null){$store->unlock($lock);}
    foreach($tables as$table){$db->query('DROP TABLE IF EXISTS `'.$table.'`');}
}
