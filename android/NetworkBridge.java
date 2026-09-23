package tech.devwork.mdiag;

import java.io.*;
import java.lang.reflect.*;
import java.net.URI;
import java.security.KeyStore;
import java.util.*;
import javax.net.ssl.*;

/**
 * Граница реального OkHttp 3 из APK 7.00.014 (обфусцированные классы j.*).
 * Меняет только известные адреса диагностики. Запрос, тело, подписи, cookies и
 * методы остаются исходными. Проверки наличия Интернета не затрагиваются.
 * Локальные вызовы получают отдельную копию клиента с системным TLS. Внешние
 * вызовы продолжают использовать исходный клиент и его настройки сертификатов.
 */
public final class NetworkBridge {
    private static final String BASE = "__MDIAG_BASE__";
    private static File traceFile;
    private static final Map<Object,Object> clients = new WeakHashMap<Object,Object>();
    private NetworkBridge() {}

    /** Повторное применение безопасно: уже локальный URL не получает второй /xdiag. */
    public static String route(String value) {
        try {
            URI u = new URI(value), local = new URI(BASE);
            String host = u.getHost();
            if (host == null || !("http".equals(u.getScheme()) || "https".equals(u.getScheme()))) return value;
            host = host.toLowerCase(Locale.US);
            if (host.equals(local.getHost())) return value;
            String path = u.getRawPath();
            if (path == null || path.length() == 0) path = "/";
            String prefix = "";
            if (host.equals("services.x-diag.info") || host.equals("config.x-diag.info")) {
                if (path.equals("/dev")) path = "/";
                else if (path.startsWith("/dev/")) path = path.substring(4);
            } else if (host.equals("diag.devwork.local") || host.equals("diag.devwork.ru")) {
                // Адреса из preferences прежних mX-DIAG, не пользовательские IP.
                if (path.equals("/xdiag")) path = "/";
                else if (path.startsWith("/xdiag/")) path = path.substring(6);
            } else if (host.equals("xdiagpro.com") || host.endsWith(".xdiagpro.com")) {
                prefix = "/" + (host.equals("xdiagpro.com") ? "portal" : host.substring(0, host.length()-13));
            } else if ((host.equals("79.174.70.97") || host.equals("79.174.70.103")) &&
                    (path.startsWith("/services/") || path.startsWith("/uc/services/") ||
                     path.startsWith("/mobile/") || path.startsWith("/diag/") ||
                     path.startsWith("/opendiag/") || path.startsWith("/diagdevice/"))) {
                // Голый SOAP namespace не является запросом: его не заменяем.
            } else return value;
            return BASE + prefix + path + (u.getRawQuery()==null ? "" : "?"+u.getRawQuery())
                    + (u.getRawFragment()==null ? "" : "#"+u.getRawFragment());
        } catch (Exception ignored) { return value; }
    }

    /** Вызывается ровно в OkHttpClient.newCall: охватывает sync и async execute/enqueue. */
    public static Object newCall(Object client, Object request) {
        try {
            ClassLoader loader = client.getClass().getClassLoader();
            Class<?> requestType = Class.forName("j.a0", true, loader);
            String original = requestType.getMethod("g").invoke(request).toString();
            String target = route(original);
            if (new URI(BASE).getHost().equalsIgnoreCase(new URI(target).getHost())) {
                Object b = requestType.getMethod("f").invoke(request);
                b.getClass().getMethod("b", String.class).invoke(b, target);
                // Старый Host не должен увести nginx на другой virtual host.
                b.getClass().getMethod("a", String.class).invoke(b, "Host");
                String id = UUID.randomUUID().toString().replace("-", "");
                b.getClass().getMethod("b", String.class, String.class).invoke(b, "X-MDiag-Request-Id", id);
                request = b.getClass().getMethod("a").invoke(b);
                client = localClient(client);
                trace("request id="+id+" host="+new URI(target).getHost()+" path="+safePath(target));
            }
            // Прямой исходный RealCall.newRealCall, без повторного входа в hook.
            Method create = Class.forName("j.z", true, loader).getDeclaredMethod("a", client.getClass(), requestType, boolean.class);
            create.setAccessible(true);
            return create.invoke(null, client, request, false);
        } catch (Exception e) {
            trace("prepare_failed class="+cause(e).getClass().getName());
            throw new IllegalStateException("MDiag: cannot prepare HTTP request", cause(e));
        }
    }

    private static synchronized Object localClient(Object original) throws Exception {
        Object cached = clients.get(original);
        if (cached != null) return cached;
        final ClassLoader loader = original.getClass().getClassLoader();
        Class<?> builderType = Class.forName("j.x$b", true, loader);
        Constructor<?> constructor = builderType.getDeclaredConstructor(original.getClass());
        constructor.setAccessible(true);
        Object builder = constructor.newInstance(original);
        TrustManagerFactory tmf = TrustManagerFactory.getInstance(TrustManagerFactory.getDefaultAlgorithm());
        tmf.init((KeyStore)null);
        X509TrustManager trust = null;
        for (TrustManager t : tmf.getTrustManagers()) if (t instanceof X509TrustManager) trust = (X509TrustManager)t;
        if (trust == null) throw new SSLException("No system X509 trust manager");
        SSLContext context = SSLContext.getInstance("TLS");
        context.init(null, new TrustManager[]{trust}, null);
        // Двухаргументная перегрузка нужна OkHttp для правильного chain cleaner.
        builderType.getMethod("a", SSLSocketFactory.class, X509TrustManager.class).invoke(builder, context.getSocketFactory(), trust);
        // Штатный строгий OkHttp verifier. Android HttpsURLConnection default
        // может быть глобально заменён самим старым приложением, поэтому не берём его.
        Class<?> verifier = Class.forName("okhttp3.internal.tls.OkHostnameVerifier", true, loader);
        builderType.getMethod("a", HostnameVerifier.class).invoke(builder, verifier.getField("a").get(null));
        // Для локального домена нет pin поставщика; цепочка и имя всё равно проверяются.
        Class<?> pinner = Class.forName("j.g", true, loader);
        builderType.getMethod("a", pinner).invoke(builder, pinner.getField("c").get(null));
        Field interceptors = builderType.getDeclaredField("e");
        interceptors.setAccessible(true);
        @SuppressWarnings("unchecked") List<Object> list = (List<Object>)interceptors.get(builder);
        // Исходный BODY-логгер писал пароль и token. Наш журнал содержит только метаданные.
        for (Iterator<Object> i=list.iterator(); i.hasNext();) if (i.next().getClass().getName().equals("j.i0.a")) i.remove();
        final Class<?> type = Class.forName("j.u", true, loader);
        list.add(0, Proxy.newProxyInstance(loader, new Class<?>[]{type}, new InvocationHandler() {
            public Object invoke(Object proxy, Method method, Object[] args) throws Throwable {
                if (method.getDeclaringClass()==Object.class) {
                    if (method.getName().equals("equals")) return proxy==args[0];
                    if (method.getName().equals("hashCode")) return System.identityHashCode(proxy);
                    return "MDiag request trace";
                }
                Class<?> chainType = Class.forName("j.u$a", true, loader);
                Class<?> requestType = Class.forName("j.a0", true, loader);
                Object request = chainType.getMethod("request").invoke(args[0]);
                String id = (String)requestType.getMethod("a",String.class).invoke(request,"X-MDiag-Request-Id");
                try {
                    Object response = chainType.getMethod("a",requestType).invoke(args[0],request);
                    Class<?> responseType = Class.forName("j.c0", true, loader);
                    trace("response id="+id+" status="+responseType.getMethod("c").invoke(response)
                        +" server_id="+responseType.getMethod("a",String.class).invoke(response,"X-MDiag-Request-Id"));
                    return response;
                } catch (InvocationTargetException e) {
                    trace("network_failed id="+id+" class="+cause(e).getClass().getName());
                    throw cause(e);
                }
            }
        }));
        Object result = builderType.getMethod("a").invoke(builder);
        clients.put(original,result);
        trace("local_okhttp_ready system_tls=true");
        return result;
    }

    /** Старое приложение запрашивало IMEI до try/catch сетевого вызова. */
    public static String deviceId(Object phone, Object context) {
        try {
            File directory=(File)context.getClass().getMethod("getExternalFilesDir",String.class).invoke(context,new Object[]{null});
            if (directory!=null) traceFile=new File(directory,"mdiag-network.log");
        } catch (Exception ignored) {}
        trace("login_prepare");
        if (phone!=null) try {
            Object id=phone.getClass().getMethod("getDeviceId").invoke(phone);
            if (id instanceof String && ((String)id).length()>0) return (String)id;
        } catch (Exception e) { trace("device_id_fallback class="+cause(e).getClass().getName()); }
        try {
            Object resolver=context.getClass().getMethod("getContentResolver").invoke(context);
            ClassLoader loader=context.getClass().getClassLoader();
            Class<?> resolverType=Class.forName("android.content.ContentResolver",true,loader);
            Object id=Class.forName("android.provider.Settings$Secure",true,loader)
                .getMethod("getString",resolverType,String.class).invoke(null,resolver,"android_id");
            if (id instanceof String && ((String)id).length()>0) return (String)id;
        } catch (Exception e) { trace("android_id_unavailable class="+cause(e).getClass().getName()); }
        return "mdiag-local";
    }

    private static Throwable cause(Throwable e) {
        while (e instanceof InvocationTargetException && e.getCause()!=null) e=e.getCause();
        return e;
    }
    private static String safePath(String value) throws Exception {
        String p=new URI(value).getRawPath();
        int n=p.indexOf("/login.php/");
        return n<0 ? p : p.substring(0,n)+"/login.php/[redacted]";
    }
    private static synchronized void trace(String value) {
        String line="MDiagNetwork "+System.currentTimeMillis()+" "+value;
        System.err.println(line);
        if (traceFile!=null) try {
            if (traceFile.length()>1048576) new FileOutputStream(traceFile).close();
            FileOutputStream out=new FileOutputStream(traceFile,true);
            try { out.write((line+"\n").getBytes("UTF-8")); } finally { out.close(); }
        } catch (IOException ignored) {}
    }
}
