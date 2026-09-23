package j;
import java.util.*;
import javax.net.ssl.*;
/** JVM adapter: exact obfuscated APK ABI mapped to stock OkHttp for integration tests. */
public class x {
 public final okhttp3.OkHttpClient client;
 public x(okhttp3.OkHttpClient c){client=c;}
 public static class b {
  public final List<u> e=new ArrayList<u>();
  private final okhttp3.OkHttpClient.Builder builder;
  public b(x original){builder=original.client.newBuilder();}
  public b a(SSLSocketFactory f,X509TrustManager t){builder.sslSocketFactory(f,t);return this;}
  public b a(HostnameVerifier v){builder.hostnameVerifier(v);return this;}
  public b a(g p){builder.certificatePinner(okhttp3.CertificatePinner.DEFAULT);return this;}
  public x a(){
   for(final u item:e) builder.addInterceptor(chain->item.intercept(new u.a(){
    public a0 request(){return new a0(chain.request());}
    public c0 a(a0 r)throws java.io.IOException{return new c0(chain.proceed(r.request));}
   }).response);
   return new x(builder.build());
  }
 }
}
