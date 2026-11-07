<?php

declare(strict_types=1);

/** Real customer controller and service with offline CRM/API fixtures. */
require __DIR__ . '/fixtures/automatic-pix-fixture.php';
function is_client_logged_in():bool{return $GLOBALS['pix_logged'];}
function get_client_user_id():int{return $GLOBALS['pix_client'];}
function get_contact_user_id():int{return $GLOBALS['pix_contact'];}
function has_contact_permission(string$permission):bool{return $permission==='invoices'&&$GLOBALS['pix_permission'];}
function site_url(string$path=''):string{return 'https://crm.example.test/'.ltrim($path,'/');}
function show_error(string$message,int$status=500):void{$GLOBALS['pix_errors'][]=$status;}
function show_404():void{$GLOBALS['pix_errors'][]=404;}
function redirect(string$url,string$method='auto',?int$code=null):void{$GLOBALS['pix_redirects'][]=[$url,$code];}
final class PixTestInput
{
    public function method(bool$upper=false):string{return $upper?$_SERVER['REQUEST_METHOD']:strtolower($_SERVER['REQUEST_METHOD']);}
    public function post(string$key,bool$filter=false){return $_POST[$key]??null;}
}
final class PixTestSession
{
    public array$data=[];public array$flash=[];
    public function set_userdata(array$data):void{$this->data=$data+$this->data;}
    public function userdata(string$key){return $this->data[$key]??null;}
    public function unset_userdata(string$key):void{unset($this->data[$key]);}
    public function set_flashdata(string$key,$value):void{$this->flash[$key]=$value;}
    public function flashdata(string$key){return $this->flash[$key]??null;}
}
final class PixTestOutput
{
    public bool$profiler=true;public int$cached=1;public array$headers=[];
    public function cache($value):void{$this->cached=(int)$value;}
    public function enable_profiler($value):void{$this->profiler=(bool)$value;}
    public function set_header(string$value):self{$this->headers[]=$value;return $this;}
}
#[AllowDynamicProperties]
class ClientsController
{
    public array$rendered=[];public string$renderedView='';
    public function __construct(){foreach(get_object_vars(get_instance())as$key=>$value){$this->$key=$value;}}
    public function data(array$data):void{$this->rendered=$data;}
    public function view(string$view):void{$this->renderedView=$view;}
    public function layout():void{}
}
require dirname(__DIR__).'/controllers/Automatic_pix.php';
class PixControllerTest extends Automatic_pix
{
    public object$fixtureService;
    protected function service():object{return $this->fixtureService;}
}
function pixControllerFixture():array
{
    [$service,$store,$gateway,$invoice]=pixFixture();
    $GLOBALS['pix_logged']=true;$GLOBALS['pix_client']=7;$GLOBALS['pix_contact']=9;$GLOBALS['pix_permission']=true;$GLOBALS['pix_https']=true;
    $GLOBALS['pix_errors']=[];$GLOBALS['pix_redirects']=[];
    $_GET=[];$_POST=[];$_SERVER['QUERY_STRING']='';$_SERVER['REQUEST_METHOD']='GET';$_SERVER['CONTENT_LENGTH']=0;
    $ci=get_instance();$ci->input=new PixTestInput();$ci->session=new PixTestSession();$ci->output=new PixTestOutput();
    $controller=new PixControllerTest();$controller->fixtureService=$service;
    return [$controller,$service,$gateway,$invoice,$store];
}
$assertions=0;$cases=0;$failures=0;
function pxAssert(bool$condition,string$message):void{++$GLOBALS['assertions'];if(!$condition)throw new RuntimeException($message);}
function pxCase(string$name,callable$test):void{++$GLOBALS['cases'];try{$test();echo 'PASS '.$name.PHP_EOL;}catch(Throwable$e){++$GLOBALS['failures'];echo 'FAIL '.$name.': '.$e->getMessage().' @ '.$e->getLine().PHP_EOL;}}
function pxPost(object$controller,object$invoice,string$action='enroll'):array
{
    $controller->index($invoice->hash);$page=$controller->rendered;
    $_SERVER['REQUEST_METHOD']='POST';
    return $_POST=['action'=>$action,'pixNonce'=>$page['pix_nonce'],'pixConsent'=>'1','consentVersion'=>\Asaas\AsaasAutomaticPix::CONSENT_VERSION,
        'pixQuote'=>$page['pix_page']['quote'],'frequency'=>'MONTHLY'];
}
pxCase('Guest and unauthorized contacts cannot create a mandate',function():void{
    [$controller,$service,$gateway,$invoice]=pixControllerFixture();$GLOBALS['pix_logged']=false;$controller->index($invoice->hash);
    pxAssert($controller->rendered['requires_login']&&$gateway->api->calls===[],'Guest sees login screen only.');
    pxAssert(get_instance()->session->data['red_url']===site_url('asaas/automatic_pix/index/'.$invoice->hash),'Return URL is server-defined.');
    $GLOBALS['pix_logged']=true;$GLOBALS['pix_client']=8;$controller->index($invoice->hash);pxAssert(end($GLOBALS['pix_errors'])===403,'Another customer cannot manage mandate.');
    $GLOBALS['pix_client']=7;$GLOBALS['pix_permission']=false;$controller->index($invoice->hash);pxAssert(end($GLOBALS['pix_errors'])===403,'Invoice permission is mandatory.');
    pxAssert($gateway->api->calls===[],'Denied requests cannot reach the API.');
});
pxCase('GET is read-only, has no-store and renders a single-use bound nonce',function():void{
    [$controller,$service,$gateway,$invoice]=pixControllerFixture();$controller->index($invoice->hash);
    $nonce=$controller->rendered['pix_nonce'];
    pxAssert(preg_match('/^[a-f0-9]{64}$/D',$nonce)===1&&$gateway->api->calls===[],'GET creates only a local form nonce.');
    pxAssert(!get_instance()->output->profiler&&get_instance()->output->cached===0,'Public financial response must disable profiler/cache.');
    pxAssert(in_array('Cache-Control: no-store, private, max-age=0',get_instance()->output->headers,true),'Browser must receive no-store.');
    pxAssert(!str_contains(json_encode(get_instance()->session->data),$nonce),'Session stores only nonce digest.');
});
pxCase('Authorized POST creates one QR and replay cannot create another',function():void{
    [$controller,$service,$gateway,$invoice]=pixControllerFixture();$post=pxPost($controller,$invoice);$controller->index($invoice->hash);
    pxAssert(pixProfile()['state']==='created'&&end($GLOBALS['pix_redirects'])[1]===303,'Successful form uses PRG.');
    $_POST=$post;$controller->index($invoice->hash);
    pxAssert(end($GLOBALS['pix_errors'])===403&&count(array_filter($gateway->api->calls,fn($v)=>$v==='POST authorization'))===1,'Consumed nonce rejects a repeated POST.');
});
pxCase('Nonce cannot be used for another invoice or contact',function():void{
    [$controller,$service,$gateway,$invoice]=pixControllerFixture();pxPost($controller,$invoice);
    $other=get_instance()->db->invoice(43);$controller->index($other->hash);
    pxAssert(end($GLOBALS['pix_errors'])===403&&$gateway->api->calls===[],'Nonce is bound to invoice id/hash.');
    $_SERVER['REQUEST_METHOD']='GET';pxPost($controller,$invoice);$GLOBALS['pix_contact']=12;$controller->index($invoice->hash);
    pxAssert(end($GLOBALS['pix_errors'])===403&&$gateway->api->calls===[],'Nonce is bound to authenticated contact.');
});
pxCase('Cancel and refresh require POST with nonce even when global CSRF is absent',function():void{
    foreach(['cancel','refresh']as$action){
        [$controller,$service,$gateway,$invoice]=pixControllerFixture();$_SERVER['REQUEST_METHOD']='POST';$_POST=['action'=>$action];$controller->index($invoice->hash);
        pxAssert(end($GLOBALS['pix_errors'])===403&&$gateway->api->calls===[],'Mutation needs independent nonce.');
    }
});
pxCase('HTTP, unsupported methods and query-driven operations are rejected',function():void{
    [$controller,$service,$gateway,$invoice]=pixControllerFixture();pxPost($controller,$invoice);$GLOBALS['pix_https']=false;$controller->index($invoice->hash);
    pxAssert(end($GLOBALS['pix_errors'])===400&&$gateway->api->calls===[],'Insecure enrollment cannot reach Asaas.');
    $_SERVER['REQUEST_METHOD']='DELETE';$controller->index($invoice->hash);pxAssert(end($GLOBALS['pix_errors'])===405,'Route supports GET and POST only.');
    $_SERVER['REQUEST_METHOD']='GET';$_GET=['action'=>'cancel'];$_SERVER['QUERY_STRING']='action=cancel';$controller->index($invoice->hash);
    pxAssert(end($GLOBALS['pix_redirects'])[0]===site_url('asaas/automatic_pix/index/'.$invoice->hash)&&$gateway->api->calls===[],'Query values cannot cause a financial operation.');
});
pxCase('Raw authorization and banking fields never enter rendered data',function():void{
    [$controller,$service,$gateway,$invoice]=pixControllerFixture();pxPost($controller,$invoice);$controller->index($invoice->hash);
    $_POST=[];$_SERVER['REQUEST_METHOD']='GET';$controller->index($invoice->hash);$json=json_encode($controller->rendered);
    foreach(['qrcode_ciphertext','customer_id','scope_key','contract_id','remote_id','fictional-key']as$key){pxAssert(!str_contains($json,$key),'Controller only forwards allowed presentation fields: '.$key);}
});
asaas_receipt_unlock_all();echo $cases.' scenarios; '.$assertions.' assertions; '.$failures.' failures'.PHP_EOL;exit($failures?1:0);
