.class public final Ltech/devwork/mdiag/LanNetwork;
.super Ljava/lang/Object;

# Локальная сеть не обязана иметь INTERNET/VALIDATED. Выбираем подключённый
# Wi-Fi/Ethernet и привязываем процесс, чтобы сокеты/DNS использовали этот путь.
.method public static connected(Landroid/content/Context;)Z
    .locals 8
    :try_start
    const-string v0, "connectivity"
    invoke-virtual {p0, v0}, Landroid/content/Context;->getSystemService(Ljava/lang/String;)Ljava/lang/Object;
    move-result-object v0
    check-cast v0, Landroid/net/ConnectivityManager;
    if-eqz v0, :no
    sget v1, Landroid/os/Build$VERSION;->SDK_INT:I
    const/16 v2, 0x15
    if-lt v1, v2, :legacy
    invoke-virtual {v0}, Landroid/net/ConnectivityManager;->getAllNetworks()[Landroid/net/Network;
    move-result-object v1
    array-length v2, v1
    const/4 v3, 0x0
    :loop
    if-ge v3, v2, :legacy
    aget-object v4, v1, v3
    invoke-virtual {v0, v4}, Landroid/net/ConnectivityManager;->getNetworkCapabilities(Landroid/net/Network;)Landroid/net/NetworkCapabilities;
    move-result-object v5
    if-eqz v5, :next
    const/4 v6, 0x1
    invoke-virtual {v5, v6}, Landroid/net/NetworkCapabilities;->hasTransport(I)Z
    move-result v6
    if-nez v6, :check_connected
    const/4 v6, 0x3
    invoke-virtual {v5, v6}, Landroid/net/NetworkCapabilities;->hasTransport(I)Z
    move-result v6
    if-eqz v6, :next
    :check_connected
    invoke-virtual {v0, v4}, Landroid/net/ConnectivityManager;->getNetworkInfo(Landroid/net/Network;)Landroid/net/NetworkInfo;
    move-result-object v5
    if-eqz v5, :next
    invoke-virtual {v5}, Landroid/net/NetworkInfo;->isConnected()Z
    move-result v5
    if-eqz v5, :next
    sget v5, Landroid/os/Build$VERSION;->SDK_INT:I
    const/16 v6, 0x17
    if-lt v5, v6, :bind_old
    invoke-virtual {v0}, Landroid/net/ConnectivityManager;->getBoundNetworkForProcess()Landroid/net/Network;
    move-result-object v5
    invoke-virtual {v4, v5}, Landroid/net/Network;->equals(Ljava/lang/Object;)Z
    move-result v5
    if-nez v5, :yes
    invoke-virtual {v0, v4}, Landroid/net/ConnectivityManager;->bindProcessToNetwork(Landroid/net/Network;)Z
    move-result v5
    goto :bound
    :bind_old
    invoke-static {v4}, Landroid/net/ConnectivityManager;->setProcessDefaultNetwork(Landroid/net/Network;)Z
    move-result v5
    :bound
    if-eqz v5, :next
    const-string v5, "MDiagNetwork"
    const-string v6, "Process bound to connected LAN WiFi/Ethernet"
    invoke-static {v5, v6}, Landroid/util/Log;->i(Ljava/lang/String;Ljava/lang/String;)I
    :yes
    const/4 v5, 0x1
    return v5
    :next
    add-int/lit8 v3, v3, 0x1
    goto :loop
    :legacy
    invoke-virtual {v0}, Landroid/net/ConnectivityManager;->getActiveNetworkInfo()Landroid/net/NetworkInfo;
    move-result-object v1
    if-eqz v1, :no
    invoke-virtual {v1}, Landroid/net/NetworkInfo;->isConnected()Z
    move-result v1
    :try_end
    return v1
    .catch Ljava/lang/Exception; {:try_start .. :try_end} :error
    :error
    move-exception v0
    const-string v1, "MDiagNetwork"
    const-string v2, "Cannot select LAN network"
    invoke-static {v1, v2, v0}, Landroid/util/Log;->w(Ljava/lang/String;Ljava/lang/String;Ljava/lang/Throwable;)I
    :no
    const/4 v0, 0x0
    return v0
.end method
