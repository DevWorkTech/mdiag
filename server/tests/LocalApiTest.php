<?php

declare(strict_types=1);
namespace DevWorkTech\MDiag\Tests;
use DevWorkTech\MDiag\MDiagServiceProvider;
use DevWorkTech\MDiag\Models\{LocalUser, ScannerAccessRule, DiagnosticBrand, DiagnosticPackageVersion};
use Illuminate\Support\Facades\{Hash, Http};
use Orchestra\Testbench\TestCase;

/** Регрессии границы локального доступа: подмена SN, отзыв прав и отсутствие egress. */
final class LocalApiTest extends TestCase
{
    protected function getPackageProviders($app): array { return [MDiagServiceProvider::class]; }
    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $app['config']->set('app.key', 'base64:' . base64_encode(str_repeat('k', 32)));
        $app['config']->set('cache.default', 'array');
        $app['config']->set('mdiag-dwt.package_root', sys_get_temp_dir() . '/mdiag-test-' . getmypid());
    }
    protected function setUp(): void
    {
        parent::setUp();
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');
        // Любой Laravel HTTP-запрос в тестах считается ошибкой, включая неизвестные API.
        Http::preventStrayRequests();
        Http::fake(fn () => throw new \RuntimeException('LOCAL API ATTEMPTED EGRESS'));
    }
    private function url(string $suffix = ''): string { return 'https://diag.devwork.local/xdiag/' . $suffix; }
    private function account(): LocalUser
    {
        return LocalUser::create(['provider'=>'xdiag','login'=>'tester','password'=>Hash::make('password-test'),
            'active'=>true,'downloads_allowed'=>true,'allowed_modules'=>['BENZ']]);
    }
    private function login(): string
    {
        return $this->postJson($this->url('?action=passport_service.login'), ['login_key'=>'tester','password'=>'password-test'])
            ->assertOk()->assertJsonPath('code',0)->json('data.token');
    }
    public function test_login_expiry_revocation_and_no_external_requests(): void
    {
        $u=$this->account();$token=$this->login();
        $this->withToken($token)->getJson($this->url('?action=userinfo.get_base_info'))->assertJsonPath('code',0);
        $u->update(['expires_at'=>now()->subSecond(),'expired_message'=>'Продлите локальный доступ']);
        $this->withToken($token)->getJson($this->url('?action=userinfo.get_base_info'))
            ->assertJsonPath('code',900003)->assertJsonPath('msg','Продлите локальный доступ');
        Http::assertNothingSent();
    }
    public function test_unknown_routes_never_proxy_and_bootstrap_has_only_local_urls(): void
    {
        $urls=$this->getJson($this->url('?action=config_service.urls'))->assertOk()->json('data.urls');
        foreach($urls as $u){$this->assertSame('diag.devwork.local',parse_url($u['value'],PHP_URL_HOST));}
        $this->account();$token=$this->login();
        $this->withToken($token)->postJson($this->url('unknown.php'),['serialNo'=>'DO-NOT-SEND'])
            ->assertJsonPath('code',900008);
        Http::assertNothingSent();
    }
    public function test_download_acl_cannot_be_bypassed_by_id_or_other_serial(): void
    {
        $u=$this->account();
        ScannerAccessRule::create(['provider'=>'xdiag','serial'=>'968590000001','user_id'=>$u->id,'downloads_allowed'=>true]);
        $brand=DiagnosticBrand::create(['provider'=>'xdiag','kind'=>'diagnostic','code'=>'BMW','name'=>'BMW','enabled'=>true]);
        $v=DiagnosticPackageVersion::create(['brand_id'=>$brand->id,'version'=>'1','source_serial'=>'','relative_path'=>'test.zip','size_bytes'=>1,'md5'=>str_repeat('a',32),'sha256'=>str_repeat('a',64),'active'=>true]);
        $token=$this->login();
        $path='mobile/softCenter/downloadDiagSoftWs.action?versionDetailId='.$v->id.'&serialNo=';
        $this->withToken($token)->getJson($this->url($path.'968590000001'))->assertJsonPath('code',900004);
        $this->withToken($token)->getJson($this->url($path.'968590000002'))->assertJsonPath('code',900005);
        $u->update(['active'=>false,'denied_message'=>'Заблокирован оператором']);
        $this->withToken($token)->getJson($this->url($path.'968590000001'))->assertJsonPath('code',900002);
        Http::assertNothingSent();
    }
    public function test_soap_signature_and_xml_escaping(): void
    {
        $u=$this->account();$token=$this->login();
        $sign=md5('xdiasft3S1'.$token);
        $xml='<s:Envelope xmlns:s="http://schemas.xmlsoap.org/soap/envelope/"><s:Header><authenticate><cc>'.$u->id.'</cc><sign>'.$sign.'</sign></authenticate></s:Header><s:Body><getRegisteredProductsForPad><productType>xdiasft3S</productType><requestType>1</requestType></getRegisteredProductsForPad></s:Body></s:Envelope>';
        $r=$this->call('POST',$this->url('services/productService.php'),[],[],[],['CONTENT_TYPE'=>'text/xml'],$xml);
        $r->assertOk();$this->assertStringContainsString('<code>0</code>',$r->getContent());
        $u->update(['active'=>false,'denied_message'=>'<block>& access']);
        $r=$this->call('POST',$this->url('services/productService.php'),[],[],[],['CONTENT_TYPE'=>'text/xml'],$xml);
        $this->assertStringContainsString('&lt;block&gt;&amp; access',$r->getContent());
        $r=$this->call('POST',$this->url('services/productService.php'),[],[],[],['CONTENT_TYPE'=>'text/xml'],str_replace($sign,str_repeat('0',32),$xml));
        $this->assertStringContainsString('<code>900001</code>',$r->getContent());
        Http::assertNothingSent();
    }
    public function test_doctype_rejected_without_reading_entities(): void
    {
        $xml='<!DOCTYPE x [<!ENTITY a SYSTEM "https://example.invalid/private">]><x>&a;</x>';
        $this->call('POST',$this->url('services/productService.php'),[],[],[],['CONTENT_TYPE'=>'text/xml'],$xml)->assertJsonPath('code',1023);
        Http::assertNothingSent();
    }
    public function test_sync_soap_client_does_not_load_wsdl(): void
    {
        $client=$this->app->make(\DevWorkTech\MDiag\Services\Sync\XDiagOfficialClient::class);
        $method=new \ReflectionMethod($client,'soapClient');
        // Несуществующий адрес: создание клиента не должно выполнять DNS/GET WSDL.
        $soap=$method->invoke($client,'https://no-network.invalid/services/productService.php');
        $this->assertInstanceOf(\SoapClient::class,$soap);
        $this->assertNull($soap->__getFunctions());
    }
    public function test_fallback_uses_device_session_and_only_after_primary_failure(): void
    {
        config(['mdiag-dwt.profiles.xdiag.sync.username'=>'owner',
            'mdiag-dwt.profiles.xdiag.sync.password'=>'test-secret',
            'mdiag-dwt.profiles.xdiag.sync.fallback_login'=>['enabled'=>true,'serial_no'=>'968590000001','timezone'=>'UTC']]);
        $calls=[];
        // Сбрасываем запретный stub локального API только в тесте CLI-клиента.
        Http::swap(new \Illuminate\Http\Client\Factory());
        Http::preventStrayRequests();
        Http::fake(function ($request) use (&$calls) {
            $calls[]=$request;
            if (count($calls)===1) { return Http::response(['code'=>100001],200); }
            return Http::response(['code'=>0,'data'=>['deviceUser'=>['cc'=>'123','token'=>'device-token'],
                'loginUser'=>['token'=>'wrong-token']]],200);
        });
        $client=$this->app->make(\DevWorkTech\MDiag\Services\Sync\XDiagOfficialClient::class);
        (new \ReflectionMethod($client,'authenticate'))->invoke($client,static function ($message) {});
        $this->assertCount(2,$calls);
        $this->assertSame('968590000001',$calls[1]['serialNo']);
        $this->assertSame(md5(md5('test-secret').$calls[1]['dateTime']),$calls[1]['password']);
        $this->assertSame('device-token',(new \ReflectionProperty($client,'token'))->getValue($client));
        $this->assertSame('123',(new \ReflectionProperty($client,'userId'))->getValue($client));
        Http::swap(new \Illuminate\Http\Client\Factory());
        Http::preventStrayRequests();
        Http::fake(['*'=>Http::response(['code'=>0,'data'=>['token'=>'primary-token','user'=>['user_id'=>'321']]],200)]);
        $client=$this->app->make(\DevWorkTech\MDiag\Services\Sync\XDiagOfficialClient::class);
        (new \ReflectionMethod($client,'authenticate'))->invoke($client,static function ($message) {});
        Http::assertSentCount(1);
    }
    public function test_catalog_filters_modules_and_missing_or_other_scanner_files(): void
    {
        $u=$this->account();
        $scanner=ScannerAccessRule::create(['provider'=>'xdiag','serial'=>'968590000001','user_id'=>$u->id,'downloads_allowed'=>true]);
        $root=config('mdiag-dwt.package_root');
        if(!is_dir($root)){mkdir($root,0700,true);}
        file_put_contents($root.'/test.zip','local-package');
        foreach (['BENZ','BMW'] as $code) {
            $b=DiagnosticBrand::create(['provider'=>'xdiag','kind'=>'diagnostic','code'=>$code,'name'=>$code,'enabled'=>true]);
            DiagnosticPackageVersion::create(['brand_id'=>$b->id,'version'=>'1','source_serial'=>'968590000001','relative_path'=>'test.zip','size_bytes'=>13,'md5'=>md5('local-package'),'sha256'=>hash('sha256','local-package'),'active'=>true]);
            DiagnosticPackageVersion::create(['brand_id'=>$b->id,'version'=>'2','source_serial'=>'968590000002','relative_path'=>'test.zip','size_bytes'=>13,'md5'=>md5('local-package'),'sha256'=>hash('sha256','local-package'),'active'=>true]);
        }
        $catalog=$this->app->make(\DevWorkTech\MDiag\Services\Local\LocalCatalog::class);
        $rows=$catalog->versions($u,$scanner,'diagnostic',true,[],'local-test-token');
        $this->assertCount(1,$rows);
        $this->assertSame('BENZ',$rows[0]['softPackageId']);
        $this->assertSame('1',$rows[0]['versionNo']);
        $this->assertSame('diag.devwork.local',parse_url($rows[0]['url'],PHP_URL_HOST));
        unlink($root.'/test.zip');
        $this->assertSame([],$catalog->versions($u,$scanner,'diagnostic',true,[],'local-test-token'));
        Http::assertNothingSent();
    }
    /** Запросы воспроизводят APK: заголовки cc/sign, без Bearer и token. */
    public function test_native_download_headers_range_and_revocation(): void
    {
        $u=$this->account();$token=$this->login();
        ScannerAccessRule::create(['provider'=>'xdiag','serial'=>'968590000001','user_id'=>$u->id,'downloads_allowed'=>true]);
        $brand=DiagnosticBrand::create(['provider'=>'xdiag','kind'=>'diagnostic','code'=>'BENZ','name'=>'Mercedes','enabled'=>true]);
        $root=config('mdiag-dwt.package_root');if(!is_dir($root)){mkdir($root,0700,true);}
        file_put_contents($root.'/native.zip','0123456789');
        $v=DiagnosticPackageVersion::create(['brand_id'=>$brand->id,'version'=>'1','source_serial'=>'968590000001',
            'relative_path'=>'native.zip','size_bytes'=>10,'md5'=>md5('0123456789'),'sha256'=>hash('sha256','0123456789'),'active'=>true]);
        $params=['serialNo'=>'968590000001','versionDetailId'=>(string)$v->id];
        $url=$this->url('mobile/softCenter/downloadDiagSoftWs.action?'.http_build_query($params));
        $headers=['cc'=>(string)$u->id,'sign'=>md5(implode('',array_values($params)).$token)];
        // Даже при глобально включённом X-Sendfile пакет должен отдаваться через PHP.
        \Symfony\Component\HttpFoundation\BinaryFileResponse::trustXSendfileTypeHeader();
        $this->withHeaders($headers+['Range'=>'bytes=2-5','X-Sendfile-Type'=>'X-Sendfile'])->get($url)
            ->assertStatus(206)->assertHeader('Content-Range','bytes 2-5/10')->assertHeader('Content-Length','4')
            ->assertHeaderMissing('X-Sendfile')->assertHeaderMissing('X-Accel-Redirect');
        $this->withHeaders($headers+['Range'=>'bytes=100-'])->get($url)->assertStatus(416);
        $this->withHeaders($headers)->head($url)->assertOk()->assertHeader('Content-Length','10');
        $this->withHeaders(['cc'=>(string)$u->id,'sign'=>str_repeat('0',32)])->getJson($url)
            ->assertStatus(401)->assertJsonPath('code',900001);
        $u->update(['downloads_allowed'=>false]);
        $this->withHeaders($headers)->getJson($url)->assertStatus(403)->assertJsonPath('code',900006);
        unlink($root.'/native.zip');Http::assertNothingSent();
    }

    /** BaseManager: сортировка ключей, сырые значения, подпись в query без самого token. */
    public function test_native_json_signature_is_bound_to_request_and_session(): void
    {
        $u=$this->account();$token=$this->login();
        $params=['action'=>'userinfo.get_base_info','app_id'=>'21035','lan'=>'ru','user_id'=>(string)$u->id,'ver'=>'5.3.0'];
        ksort($params,SORT_STRING);$parts=[];
        foreach($params as $k=>$v){$parts[]=$k.'='.$v;}
        $params['sign']=md5(implode('&',$parts).$token);
        $this->getJson($this->url('?'.http_build_query($params)))->assertOk()->assertJsonPath('code',0)
            ->assertJsonPath('data.user_id',(string)$u->id);
        // Профиль e0: плоская data; вход i: вложенная data.user.
        $this->postJson($this->url('?action=passport_service.login'), ['login_key'=>'tester','password'=>'password-test'])
            ->assertJsonPath('data.user.user_id',(string)$u->id)->assertJsonPath('data.user.type',2);
        $params['lan']='en';
        $this->getJson($this->url('?'.http_build_query($params)))->assertJsonPath('code',900001);
        Http::assertNothingSent();
    }

    /** Bootstrap из APK меняет маршрут SOAP; внешний хост не получает подпись сессии. */
    public function test_sync_resolves_advertised_soap_urls_and_explicit_override(): void
    {
        Http::swap(new \Illuminate\Http\Client\Factory());
        Http::preventStrayRequests();
        Http::fake(['*' => Http::response(['code'=>0,'data'=>['urls'=>[
            ['key'=>'productservice.*','value'=>'https://services.x-diag.info/changed/product.php?wsdl'],
            ['key'=>'xdigpaddiagsoftservice.*','value'=>'https://untrusted.invalid/capture'],
        ]]],200)]);
        $client=$this->app->make(\DevWorkTech\MDiag\Services\Sync\XDiagOfficialClient::class);
        (new \ReflectionMethod($client,'refreshServiceUrls'))->invoke($client,static function ($message) {});
        $method=new \ReflectionMethod($client,'soapEndpoints');
        $urls=$method->invoke($client,'product');
        $this->assertSame('https://services.x-diag.info/changed/product.php?wsdl',$urls[0]);
        $this->assertNotContains('https://untrusted.invalid/capture',$method->invoke($client,'diagnostic'));
        Http::assertSent(function ($r) {
            return $r->method()==='GET' && !isset($r['password']) && !isset($r['token']);
        });
        config(['mdiag-dwt.profiles.xdiag.sync.soap_endpoints.product'=>['https://mirror.example.test/products?wsdl']]);
        $this->assertSame(['https://mirror.example.test/products?wsdl'],$method->invoke($client,'product'));
    }

    public function test_service_config_dictionary_aliases_and_redacted_rejection(): void
    {
        $client=$this->app->make(\DevWorkTech\MDiag\Services\Sync\XDiagOfficialClient::class);
        $messages=[];
        (new \ReflectionMethod($client,'parseServiceUrls'))->invoke($client, [
            'getRegisteredProductsForPad'=>'https://services.x-diag.info/new/products?wsdl',
            'productservice.*'=>'http://79.174.70.103:8000/products?token=PRIVATE_TOKEN',
        ], static function ($m) use (&$messages) {$messages[]=$m;});
        $urls=(new \ReflectionMethod($client,'soapEndpoints'))->invoke($client,'product');
        $this->assertSame('https://services.x-diag.info/new/products?wsdl',$urls[0]);
        $log=implode("\n",$messages);
        $this->assertStringContainsString('79.174.70.103:8000/products',$log);
        $this->assertStringContainsString('отклонён',$log);
        $this->assertStringNotContainsString('PRIVATE_TOKEN',$log);
    }

    /** Реальный bootstrap: IP и /services/v2/ должны сохраняться для всех трёх сервисов. */
    public function test_sync_accepts_official_ip_service_configuration(): void
    {
        Http::swap(new \Illuminate\Http\Client\Factory());
        Http::preventStrayRequests();
        $base='https://79.174.70.97/services/v2/';
        Http::fake(['*'=>Http::response(['code'=>0,'data'=>['urls'=>[
            ['key'=>'getRegisteredProductsForPad','value'=>$base.'productService.php?wsdl'],
            ['key'=>'productservice.*','value'=>$base.'productService.php?wsdl'],
            ['key'=>'queryLatestDiagSofts','value'=>$base.'xdigPadDiagSoftService.php?wsdl'],
            ['key'=>'xdigpaddiagsoftservice.*','value'=>$base.'xdigPadDiagSoftService.php?wsdl'],
            ['key'=>'xdigpadpublicsoftservice.*','value'=>$base.'xdigPadPublicSoftService.php?wsdl'],
        ]]],200)]);
        $client=$this->app->make(\DevWorkTech\MDiag\Services\Sync\XDiagOfficialClient::class);
        $messages=[];
        (new \ReflectionMethod($client,'refreshServiceUrls'))->invoke($client,
            static function ($message) use (&$messages) {$messages[]=$message;});
        $method=new \ReflectionMethod($client,'soapEndpoints');
        foreach (['product'=>'productService','diagnostic'=>'xdigPadDiagSoftService','public'=>'xdigPadPublicSoftService'] as $kind=>$file) {
            $this->assertSame($base.$file.'.php?wsdl',$method->invoke($client,$kind)[0]);
        }
        $this->assertStringContainsString('получено SOAP-адресов 3',implode("\n",$messages));
        $this->assertStringNotContainsString('отклонён',implode("\n",$messages));
        Http::assertSentCount(1);
        Http::assertSent(fn ($r)=>$r->url()==='https://services.x-diag.info/?action=config_service.urls&app_id=21035&ver=5.3.0');
        // Разрешён именно подтверждённый IP, а не произвольный адрес из сети.
        $trust=new \ReflectionMethod($client,'trustedSoapUrl');
        foreach (['https://79.174.70.97.evil.invalid/services/v2/productService.php',
            'https://79.174.70.98/services/v2/productService.php',
            'https://127.0.0.1/services/v2/productService.php',
            'https://user:pass@79.174.70.97/services/v2/productService.php'] as $url) {
            $this->assertFalse($trust->invoke($client,$url));
        }
    }

    /** Основной сайт имеет другой APP_URL; вход и bootstrap остаются на домене MDiag. */
    public function test_local_domain_and_form_login_are_independent_of_app_url(): void
    {
        config(['app.url'=>'https://main.example.test']);
        $this->account();
        $this->post($this->url('?action=passport_service.login'),
            ['login_key'=>'tester','password'=>'password-test','type'=>'0','app_id'=>'21035','ver'=>'5.3.0'])
            ->assertOk()->assertJsonPath('code',0)->assertJsonPath('data.user.user_name','tester');
        $urls=$this->getJson($this->url('?action=config_service.urls'))->assertOk()->json('data.urls');
        $byKey=array_column($urls,'value','key');
        foreach (['login','config.urls','productservice.*','xdigpaddiagsoftservice.*','xdigpadpublicsoftservice.*'] as $key) {
            $this->assertArrayHasKey($key,$byKey);
            $this->assertStringStartsWith('https://diag.devwork.local/xdiag/',$byKey[$key]);
        }
        $this->post('https://main.example.test/xdiag/?action=passport_service.login',
            ['login_key'=>'tester','password'=>'password-test'])->assertNotFound();
        Http::assertNothingSent();
    }
    public function test_health_and_debug_trace_redact_secrets(): void
    {
        config(['app.debug'=>true]);
        $this->account();
        $this->getJson($this->url('health'))->assertOk()->assertJsonPath('service','MDiag')
            ->assertHeader('X-MDiag-Trace','written');
        $this->postJson($this->url('?action=passport_service.login'),
            ['login_key'=>'tester','password'=>'password-test'])->assertJsonPath('code',0);
        $file=storage_path('logs/mdiag-debug-'.gmdate('Y-m-d').'.log');
        $log=file_get_contents($file);
        $this->assertStringContainsString('passport_service.login',$log);
        $this->assertStringNotContainsString('password-test',$log);
        $this->assertStringNotContainsString('tester',$log);
        $this->artisan('mdiag:doctor')->assertSuccessful();
        Http::assertNothingSent();
    }

    public function test_web_module_stubs_and_content_list_never_proxy(): void
    {
        $this->getJson($this->url('customers'))->assertStatus(501)->assertJsonPath('code',900008);
        $this->getJson($this->url('workshop'))->assertStatus(501);
        $this->artisan('mdiag:sync',['provider'=>'xdiag','--content'=>['all'],'--list'=>true])->assertSuccessful();
        $this->artisan('mdiag:sync',['provider'=>'xdiag','--content'=>['invalid']])->assertFailed();
        Http::assertNothingSent();
    }

    public function test_public_content_sync_and_local_read(): void
    {
        Http::swap(new \Illuminate\Http\Client\Factory());
        Http::preventStrayRequests();
        Http::fake(['http://repairdata.xdiagpro.com/*'=>Http::response('<p>Repair manual</p><script>fetch("https://external.invalid")</script>',200)]);
        $web=app(\DevWorkTech\MDiag\Modules\Web\WebContent::class);
        $web->sync(['repairdata'],false,static function ($m) {});
        $response=$web->response('repairdata/newmain/','GET');
        $this->assertSame(200,$response->getStatusCode());
        $this->assertStringContainsString('Repair manual',$response->getContent());
        $this->assertStringNotContainsString('fetch',$response->getContent());
        $this->assertStringContainsString('text/plain',$response->headers->get('Content-Type'));
        Http::assertSentCount(1);
        config(['mdiag-dwt.web_content.modules.faq.sources'=>['http://127.0.0.1/private']]);
        $this->expectException(\RuntimeException::class);
        $web->sync(['faq'],false,static function ($m) {});
    }
}

