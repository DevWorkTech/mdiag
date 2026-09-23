import tech.devwork.mdiag.NetworkBridge;
import com.sun.net.httpserver.*;
import java.io.*;
import java.net.*;
import java.security.*;
import java.security.cert.*;
import java.util.concurrent.*;
import javax.net.ssl.*;
import okhttp3.*;

/** Real HTTP/TLS exchanges through the production bridge with verified APK ABI adapters. */
public class NetworkBridgeTest {
 static void check(boolean value,String message){if(!value)throw new AssertionError(message);}
 static HttpsServer server(int port,String file)throws Exception{
  KeyStore ks=KeyStore.getInstance("JKS");try(InputStream in=new FileInputStream(file)){ks.load(in,"local-test".toCharArray());}
  KeyManagerFactory km=KeyManagerFactory.getInstance(KeyManagerFactory.getDefaultAlgorithm());km.init(ks,"local-test".toCharArray());
  SSLContext c=SSLContext.getInstance("TLS");c.init(km.getKeyManagers(),null,null);
  HttpsServer s=HttpsServer.create(new InetSocketAddress("localhost",port),0);s.setHttpsConfigurator(new HttpsConfigurator(c));
  s.createContext("/", e->{
   String body=new String(read(e.getRequestBody()),"UTF-8");
   String id=e.getRequestHeaders().getFirst("X-MDiag-Request-Id");
   String result=e.getRequestMethod()+"|"+e.getRequestURI()+"|"+body+"|"+e.getRequestHeaders().getFirst("Host");
   if(id!=null)e.getResponseHeaders().set("X-MDiag-Request-Id",id);
   byte[] bytes=result.getBytes("UTF-8");e.sendResponseHeaders(200,bytes.length);e.getResponseBody().write(bytes);e.close();
  });s.start();return s;
 }
 static byte[] read(InputStream in)throws IOException{ByteArrayOutputStream out=new ByteArrayOutputStream();byte[] b=new byte[4096];int n;while((n=in.read(b))!=-1)out.write(b,0,n);return out.toByteArray();}
 static j.z call(j.x client,String url){return (j.z)NetworkBridge.newCall(client,new j.a0(new Request.Builder().url(url).header("Host","services.x-diag.info").post(RequestBody.create(MediaType.parse("application/x-www-form-urlencoded"),"login_key=local&password=a%2Bb%26c&ver=5.3.0")).build()));}
 public static void main(String[] args)throws Exception{
  check(NetworkBridge.route("https://services.x-diag.info:8008/dev/?action=passport_service.login").equals("https://localhost:18443/xdiag/?action=passport_service.login"),"dev origin");
  check(NetworkBridge.route("https://79.174.70.97:8000/services/v2/productService.php?wsdl").equals("https://localhost:18443/xdiag/services/v2/productService.php?wsdl"),"SOAP origin");
  check(NetworkBridge.route("https://79.174.70.97").equals("https://79.174.70.97"),"SOAP namespace");
  check(NetworkBridge.route("http://repairdata.xdiagpro.com/newmain/?source=app").equals("https://localhost:18443/xdiag/repairdata/newmain/?source=app"),"web origin");
  check(NetworkBridge.route("https://diag.devwork.local/xdiag/?action=passport_service.login").equals("https://localhost:18443/xdiag/?action=passport_service.login"),"cached old host");
  check(NetworkBridge.route("http://www.google.com/generate_204").equals("http://www.google.com/generate_204"),"Internet probe");
  check(NetworkBridge.route("https://services.x-diag.info.evil.test/").equals("https://services.x-diag.info.evil.test/"),"host boundary");
  String local=NetworkBridge.route("https://services.x-diag.info/?a=1");check(NetworkBridge.route(local).equals(local),"idempotent");
  HttpsServer good=server(18443,args[0]),wrong=server(18444,args[1]),untrusted=server(18445,args[2]);
  try{
   X509TrustManager reject=new X509TrustManager(){public void checkClientTrusted(X509Certificate[] c,String a)throws CertificateException{throw new CertificateException();}public void checkServerTrusted(X509Certificate[] c,String a)throws CertificateException{throw new CertificateException();}public X509Certificate[] getAcceptedIssuers(){return new X509Certificate[0];}};
   SSLContext sc=SSLContext.getInstance("TLS");sc.init(null,new TrustManager[]{reject},null);
   j.x original=new j.x(new OkHttpClient.Builder().sslSocketFactory(sc.getSocketFactory(),reject).build());
   j.z request=call(original,"https://services.x-diag.info:8008/dev/?action=passport_service.login");
   try(Response r=request.call.execute()){
    check(r.code()==200,"TLS and HTTP success");check(r.header("X-MDiag-Request-Id").matches("[a-f0-9]{32}"),"correlation");
    check(r.body().string().equals("POST|/xdiag/?action=passport_service.login|login_key=local&password=a%2Bb%26c&ver=5.3.0|localhost:18443"),"method/body/path/Host preserved");
   }
   for(String url:new String[]{"https://localhost:18444/xdiag/","https://localhost:18445/xdiag/","https://127.0.0.1:18443/"}){
    boolean rejected=false;try(Response ignored=call(original,url).call.execute()){}catch(IOException expected){rejected=true;}
    check(rejected,"wrong hostname/untrusted certificate/external original trust: "+url);
   }
   CountDownLatch done=new CountDownLatch(1);final Throwable[] error={null};
   call(original,"https://services.x-diag.info/?action=config_service.urls").call.enqueue(new Callback(){
    public void onFailure(Call c,IOException e){error[0]=e;done.countDown();}
    public void onResponse(Call c,Response r){r.close();done.countDown();}
   });check(done.await(10,TimeUnit.SECONDS)&&error[0]==null,"async call");
   original.client.dispatcher().executorService().shutdownNow();
   System.out.println("NetworkBridge integration: routing, real TLS, hostname, body, sync/async OK");
  }finally{good.stop(0);wrong.stop(0);untrusted.stop(0);}
 }
}
