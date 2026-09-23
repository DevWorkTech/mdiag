<?php
declare(strict_types=1);
namespace DevWorkTech\MDiag\Tests;

use DevWorkTech\MDiag\MDiagServiceProvider;
use DevWorkTech\MDiag\Models\{LocalUser, ScannerAccessRule};
use Illuminate\Support\Facades\{Hash, Http};
use Orchestra\Testbench\TestCase;

/** Сценарий APK: form login -> подписанный профиль -> SOAP -> смена пароля -> новый вход. */
final class PassportProtocolTest extends TestCase
{
    protected function getPackageProviders($app): array { return [MDiagServiceProvider::class]; }
    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default','testing');
        $app['config']->set('database.connections.testing',['driver'=>'sqlite','database'=>':memory:','prefix'=>'']);
        $app['config']->set('app.key','base64:'.base64_encode(str_repeat('p',32)));
        $app['config']->set('cache.default','array');
    }
    protected function setUp(): void
    {
        parent::setUp();
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        Http::preventStrayRequests();
        Http::fake(fn()=>throw new \RuntimeException('Unexpected upstream request'));
    }
    private function url(string $path=''): string { return 'https://diag.devwork.tech/xdiag/'.$path; }
    private function signed(string $action, array $fields, LocalUser $user, string $token): string
    {
        // BaseManager.d(): metadata в query, остальные поля остаются form body.
        $query=['action'=>$action,'app_id'=>'0123456789','user_id'=>(string)$user->id,'ver'=>'5.3.0'];
        $values=$query+$fields;ksort($values,SORT_STRING);
        $source=implode('&',array_map(fn($k)=>$k.'='.$values[$k],array_keys($values)));
        return $this->url('?'.http_build_query($query+['sign'=>md5($source.$token)]));
    }
    public function test_real_form_protocol_profile_soap_password_and_logout_without_egress(): void
    {
        $password='local+ пароль & 123';
        $user=LocalUser::create(['provider'=>'xdiag','login'=>'workshop','password'=>Hash::make($password),
            'active'=>true,'downloads_allowed'=>true,'expires_at'=>now()->addDay()]);
        ScannerAccessRule::create(['provider'=>'xdiag','serial'=>'968590000001','user_id'=>$user->id,'downloads_allowed'=>true]);
        $requestId=str_repeat('c',32);
        $login=$this->withHeader('X-MDiag-Request-Id',$requestId)->post($this->url('?action=passport_service.login'),[
            'app_id'=>'0123456789','ver'=>'5.3.0','login_key'=>'workshop','password'=>$password,
            'time'=>'2026-09-23 12:00:00','type'=>'0','device_token'=>'','DeviceId'=>'local-device',
            'manuf'=>'test','model'=>'tablet','board'=>'test','device'=>'test','product'=>'test','sdk'=>'35']);
        $login->assertOk()->assertHeader('X-MDiag-Request-Id',$requestId)->assertJsonPath('code',0)
            ->assertJsonPath('data.user.user_id',(string)$user->id)->assertJsonPath('data.user.valid',true)
            ->assertJsonPath('data.xmpp.domain','diag.devwork.tech')
            ->assertJsonPath('data.user.endTime',$user->expires_at->getTimestamp()*1000);
        $token=$login->json('data.token');$this->assertSame(64,strlen($token));
        $this->post($this->signed('userinfo.get_base_info_car_logo',['lan'=>'ru'],$user,$token),['lan'=>'ru'])
            ->assertOk()->assertJsonPath('code',0)->assertJsonPath('data.user_name','workshop');
        $fields=['company_name'=>'Моя мастерская','contact'=>'Мастер','active'=>'0'];
        $this->post($this->signed('userinfo.set_base',$fields,$user,$token),$fields)->assertJsonPath('code',0);
        $this->assertTrue($user->fresh()->active);
        $this->post($this->signed('userinfo.get_base_info_car_logo',[],$user,$token))
            ->assertJsonPath('data.company_name','Моя мастерская')->assertJsonPath('data.contact','Мастер');
        $xml='<s:Envelope xmlns:s="http://schemas.xmlsoap.org/soap/envelope/"><s:Header><authenticate><cc>'.$user->id.
            '</cc><sign>'.md5('xdiasft3S1'.$token).'</sign></authenticate></s:Header><s:Body><getRegisteredProductsForPad>'.
            '<productType>xdiasft3S</productType><requestType>1</requestType></getRegisteredProductsForPad></s:Body></s:Envelope>';
        $r=$this->call('POST',$this->url('services/v2/productService.php'),[],[],[],['CONTENT_TYPE'=>'text/xml'],$xml);
        $r->assertOk()->assertSee('968590000001')->assertSee('<code>0</code>',false);
        $fields=['pw'=>$password,'chpw'=>'new-local-password'];
        $this->post($this->signed('userinfo.set_password',$fields,$user,$token),$fields)->assertJsonPath('code',0);
        $this->get($this->signed('userinfo.get_base_info',[],$user,$token))->assertJsonPath('code',900001);
        $this->post($this->url('?action=passport_service.login'),['login_key'=>'workshop','password'=>$password])
            ->assertJsonPath('code',100001);
        $token2=$this->post($this->url('?action=passport_service.login'),['login_key'=>'workshop','password'=>'new-local-password'])
            ->assertJsonPath('code',0)->json('data.token');
        $this->post($this->signed('passport_service.logout',[],$user,$token2))->assertJsonPath('code',0);
        $this->get($this->signed('userinfo.get_base_info',[],$user,$token2))->assertJsonPath('code',900001);
        Http::assertNothingSent();
    }
    public function test_wrong_domain_head_and_invalid_correlation_id(): void
    {
        $this->head($this->url('health'))->assertOk();
        $this->get('https://another.example/xdiag/health')->assertNotFound();
        $r=$this->withHeader('X-MDiag-Request-Id','not-a-safe-id')->get($this->url('health'));
        $r->assertOk();$this->assertMatchesRegularExpression('/^[a-f0-9]{32}$/',$r->headers->get('X-MDiag-Request-Id'));
    }
}
