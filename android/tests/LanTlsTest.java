package tech.devwork.mdiag;

import com.sun.net.httpserver.HttpsServer;
import com.sun.net.httpserver.HttpsConfigurator;
import java.io.FileInputStream;
import java.net.InetSocketAddress;
import java.security.KeyStore;
import javax.net.ssl.*;
import org.apache.http.client.methods.HttpGet;
import org.apache.http.impl.client.*;

/** Реальный TLS handshake: недоверенный сертификат проходит только для точного LAN-host. */
public final class LanTlsTest {
    public static void main(String[] args) throws Exception {
        KeyStore keys = KeyStore.getInstance("JKS");
        try (FileInputStream in = new FileInputStream(args[0])) { keys.load(in, "local-test".toCharArray()); }
        KeyManagerFactory km = KeyManagerFactory.getInstance(KeyManagerFactory.getDefaultAlgorithm());
        km.init(keys, "local-test".toCharArray());
        SSLContext serverContext = SSLContext.getInstance("TLS");
        serverContext.init(km.getKeyManagers(), null, null);
        HttpsServer server = HttpsServer.create(new InetSocketAddress("127.0.0.1", 0), 0);
        server.setHttpsConfigurator(new HttpsConfigurator(serverContext));
        server.createContext("/", exchange -> {
            byte[] data = "ok".getBytes("UTF-8");
            exchange.sendResponseHeaders(200, data.length);
            try (java.io.OutputStream out = exchange.getResponseBody()) { out.write(data); }
        });
        server.start();
        try {
            if (!LanTls.isLan("LOCALHOST") || LanTls.isLan("localhost.attacker.invalid") || LanTls.isLan("127.0.0.1") || LanTls.isLan(null)) {
                throw new AssertionError("Host scope is incorrect");
            }
            HttpClientBuilder builder = HttpClientBuilder.create();
            LanTls.configure(builder, SSLContext.getDefault());
            try (CloseableHttpClient client = builder.build()) {
                int port = server.getAddress().getPort();
                try (org.apache.http.client.methods.CloseableHttpResponse response = client.execute(new HttpGet("https://localhost:" + port + "/"))) {
                    if (response.getStatusLine().getStatusCode() != 200) { throw new AssertionError("LAN handshake failed"); }
                }
                boolean rejected = false;
                try (org.apache.http.client.methods.CloseableHttpResponse ignored = client.execute(new HttpGet("https://127.0.0.1:" + port + "/"))) {
                    throw new AssertionError("Non-LAN self-signed certificate was accepted");
                } catch (javax.net.ssl.SSLException expected) { rejected = true; }
                if (!rejected) { throw new AssertionError("Non-LAN certificate was not checked"); }
            }
            System.out.println("PASS: LAN self-signed TLS; non-LAN certificate rejected; exact hostname scope");
        } finally { server.stop(0); }
    }
}
