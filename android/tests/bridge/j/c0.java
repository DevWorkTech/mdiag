package j;
public class c0 {
 public final okhttp3.Response response;
 public c0(okhttp3.Response r){response=r;}
 public int c(){return response.code();}
 public String a(String name){return response.header(name);}
}
