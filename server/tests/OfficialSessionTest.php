<?php
declare(strict_types=1);
namespace DevWorkTech\MDiag\Tests;
use Orchestra\Testbench\TestCase;
use DevWorkTech\MDiag\MDiagServiceProvider;
use DevWorkTech\MDiag\Services\Sync\{OfficialSessionStore,XDiagOfficialClient};
use Illuminate\Support\Facades\{Cache,Http};

final class OfficialSessionTest extends TestCase
{
    protected function getPackageProviders($app): array { return [MDiagServiceProvider::class]; }
    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key','base64:'.base64_encode(str_repeat('s',32)));
        $app['config']->set('cache.default','array');
        $app['config']->set('mdiag-dwt.profiles.xdiag.sync.username','owner');
        $app['config']->set('mdiag-dwt.profiles.xdiag.sync.password','secret-password');
        $app['config']->set('mdiag-dwt.profiles.xdiag.sync.fallback_login.enabled',false);
    }
    private function client(): XDiagOfficialClient { return new XDiagOfficialClient(config()); }
    public function test_session_reuse_logout_and_optimize_clear(): void
    {
        Http::preventStrayRequests();
        Http::fake(['https://services.x-diag.info/*'=>Http::response(['code'=>0,'data'=>['token'=>'private-token','user'=>['user_id'=>'42']]],200,
            ['Set-Cookie'=>'session_cookie=private-cookie; Path=/; Secure; HttpOnly'])]);
        $report=static function (string $m): void {};
        $this->client()->ensureSession($report);
        $this->client()->ensureSession($report);
        Http::assertSentCount(1);
        $store=new OfficialSessionStore();
        $saved=$store->read('owner','secret-password');
        $this->assertSame('private-token',$saved['state']['token']);
        $this->assertSame('private-cookie',$saved['state']['cookies'][0]['Value']);
        $cipher=Cache::get($store->key('owner'));
        foreach (['private-token','private-cookie','secret-password'] as $secret) { $this->assertStringNotContainsString($secret,$cipher); }
        $jar=new \GuzzleHttp\Cookie\CookieJar(false,$saved['state']['cookies']);
        $this->assertSame('', $jar->withCookieHeader(new \GuzzleHttp\Psr7\Request('GET','https://repairdata.xdiagpro.com/'))->getHeaderLine('Cookie'));
        $this->artisan('mdiag:logout',['provider'=>'xdiag'])->assertSuccessful();
        $this->assertNull($store->read('owner','secret-password'));
        $this->client()->ensureSession($report);
        Http::assertSentCount(2);
        $this->artisan('optimize:clear')->assertSuccessful();
        $this->assertNull($store->read('owner','secret-password'));
        $this->client()->ensureSession($report);
        Http::assertSentCount(3);
    }
    public function test_credentials_accounts_expiry_and_failed_login(): void
    {
        $store=new OfficialSessionStore();
        $store->write('owner','secret-password',['token'=>'t','userId'=>'42'],time()+60);
        $this->assertNull($store->read('owner','changed'));
        $this->assertNull($store->read('other','secret-password'));
        $store->write('owner','secret-password',['token'=>'t','userId'=>'42'],time()-1);
        $this->assertNull($store->read('owner','secret-password'));
        Http::preventStrayRequests();
        Http::fake(['https://services.x-diag.info/*'=>Http::response(['code'=>100001],200)]);
        try { $this->client()->ensureSession(static function ($m) {}); $this->fail('Login should fail'); }
        catch (\RuntimeException $e) { $this->assertStringContainsString('login-сессию',$e->getMessage()); }
        $this->assertNull($store->read('owner','secret-password'));
        Http::assertSentCount(1);
    }
}
