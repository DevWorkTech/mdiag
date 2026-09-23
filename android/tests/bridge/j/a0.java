package j;
public class a0 {
 public final okhttp3.Request request;
 public a0(okhttp3.Request r){request=r;}
 public Object g(){return request.url();}
 public String a(String name){return request.header(name);}
 public a f(){return new a(request.newBuilder());}
 public static class a {
  private final okhttp3.Request.Builder b;
  public a(okhttp3.Request.Builder builder){b=builder;}
  public a b(String url){b.url(url);return this;}
  public a a(String header){b.removeHeader(header);return this;}
  public a b(String name,String value){b.header(name,value);return this;}
  public a0 a(){return new a0(b.build());}
 }
}
