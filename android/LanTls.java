package tech.devwork.mdiag;

import java.lang.reflect.*;
import java.security.SecureRandom;
import java.security.cert.X509Certificate;
import javax.net.ssl.*;

/**
 * Адаптер Apache HttpClient из X-DIAG 7.00.014.
 * Встроенный BKS остаётся для внешних хостов. Только точное имя LAN использует
 * TLS без проверки цепочки/имени сертификата, по настройке владельца LAN.
 * Reflection сохраняет независимость исходника от обфускации остального APK;
 * наличие точных Apache сигнатур дополнительно проверяет patch_mdiag.py.
 */
public final class LanTls {
    private static final String LAN_HOST = "__MDIAG_LAN_HOST__";
    private LanTls() {}

    static boolean isLan(String host) {
        return host != null && LAN_HOST.equalsIgnoreCase(host);
    }

    public static void configure(Object builder, SSLContext original) {
        try {
            final ClassLoader loader = builder.getClass().getClassLoader();
            final Class<?> factoryClass = Class.forName("org.apache.http.conn.ssl.SSLConnectionSocketFactory", true, loader);
            final Class<?> verifierClass = Class.forName("org.apache.http.conn.ssl.X509HostnameVerifier", true, loader);
            final Class<?> layeredClass = Class.forName("org.apache.http.conn.socket.LayeredConnectionSocketFactory", true, loader);
            final Object normal = factoryClass.getConstructor(SSLContext.class).newInstance(original);

            // Этот trust manager используется лишь после выбора точного LAN-host.
            SSLContext localContext = SSLContext.getInstance("TLS");
            localContext.init(null, new TrustManager[]{new X509TrustManager() {
                public void checkClientTrusted(X509Certificate[] c, String a) {}
                public void checkServerTrusted(X509Certificate[] c, String a) {}
                public X509Certificate[] getAcceptedIssuers() { return new X509Certificate[0]; }
            }}, new SecureRandom());
            Object verifier = Proxy.newProxyInstance(loader, new Class<?>[]{verifierClass}, new InvocationHandler() {
                public Object invoke(Object proxy, Method method, Object[] args) throws Throwable {
                    if (method.getDeclaringClass() == Object.class) { return objectMethod(proxy, method, args); }
                    boolean allowed = args != null && args.length > 0 && args[0] instanceof String && isLan((String)args[0]);
                    if (method.getReturnType() == boolean.class) { return allowed; }
                    if (!allowed) { throw new SSLException("MDiag LAN hostname mismatch"); }
                    return null;
                }
            });
            final Object local = factoryClass.getConstructor(SSLContext.class, verifierClass).newInstance(localContext, verifier);
            Object selector = Proxy.newProxyInstance(loader, new Class<?>[]{layeredClass}, new InvocationHandler() {
                public Object invoke(Object proxy, Method method, Object[] args) throws Throwable {
                    if (method.getDeclaringClass() == Object.class) { return objectMethod(proxy, method, args); }
                    String host = null;
                    if ("connectSocket".equals(method.getName())) {
                        host = (String)args[2].getClass().getMethod("getHostName").invoke(args[2]);
                    } else if ("createLayeredSocket".equals(method.getName())) {
                        host = (String)args[1];
                    }
                    boolean lan = isLan(host);
                    if (lan) { System.err.println("MDiagTLS: local TLS connection to " + LAN_HOST); }
                    try { return method.invoke(lan ? local : normal, args); }
                    catch (InvocationTargetException e) {
                        if (lan) { System.err.println("MDiagTLS: connection failed: " + e.getCause().getClass().getName()); }
                        throw e.getCause();
                    }
                }
            });
            builder.getClass().getMethod("setSSLSocketFactory", layeredClass).invoke(builder, selector);
            System.err.println("MDiagTLS: Apache LAN adapter installed for " + LAN_HOST);
        } catch (ReflectiveOperationException | java.security.GeneralSecurityException e) {
            // Не продолжаем с незаметно неработающей TLS-настройкой.
            throw new IllegalStateException("MDiagTLS: incompatible Apache client", e);
        }
    }

    private static Object objectMethod(Object proxy, Method method, Object[] args) {
        if ("equals".equals(method.getName())) { return proxy == args[0]; }
        if ("hashCode".equals(method.getName())) { return System.identityHashCode(proxy); }
        return "MDiag LAN TLS adapter";
    }
}
