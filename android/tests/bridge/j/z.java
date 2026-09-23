package j;
public class z {
 public final okhttp3.Call call;
 public z(okhttp3.Call c){call=c;}
 public static z a(x client,a0 request,boolean websocket){return new z(client.client.newCall(request.request));}
}
