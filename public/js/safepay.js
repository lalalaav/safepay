/* Jalur tulis ke blockchain di mode wallet: MetaMask tanda tangan langsung dalam ETH lokal Hardhat
   (kurs simulasi: 1 ETH = Rp 1.000.000 -> 1 Rp = 10^12 wei).
   Menggunakan pemanggilan JSON-RPC eth_sendTransaction langsung ke MetaMask agar bebas dari bug
   "could not coalesce error" pada wrapper Ethers v6, lengkap dengan fallback gas legacy & reset circuit-breaker. */
(function () {
  const cfg = window.SAFEPAY || {};
  const WEI_PER_IDR = BigInt(cfg.weiPerIdr || "1000000000000");

  let ethProvider = null;
  let activeAccount = null;
  let iface = null;
  let isConnecting = false;
  let txInProgress = false;
  let announcedMetaMask = null;

  const csrf = () => document.querySelector('meta[name=csrf-token]')?.content || "";
  const shortAddr = (a) => (a && a.length > 16 ? a.slice(0, 10) + "…" + a.slice(-6) : a || "");
  const toHexQty = (bi) => "0x" + BigInt(bi).toString(16);

  function resolveRpcUrl(preferLocalhostAlias = false) {
    if (cfg.rpc) return cfg.rpc;
    const host = window.location.hostname || "127.0.0.1";
    const port = cfg.rpcPort || 8545;
    const isLocal = host === "localhost" || host === "127.0.0.1" || host.endsWith(".test") || host.endsWith(".local");
    if (isLocal) {
      return `http://${preferLocalhostAlias ? "localhost" : "127.0.0.1"}:${port}`;
    }
    return `http://${host}:${port}`;
  }

  function fmtEth(weiBigInt) {
    const str = ethers.formatEther(weiBigInt);
    const num = Number(str);
    if (!Number.isFinite(num)) return str + " ETH";
    const trimmed = num.toFixed(4).replace(/\.?0+$/, "");
    return trimmed + " ETH";
  }

  function fmtRpFromWei(weiBigInt) {
    const rp = weiBigInt / WEI_PER_IDR;
    return "Rp " + Number(rp).toLocaleString("id-ID");
  }

  // EIP-6963: pilih ekstensi MetaMask asli meskipun ada wallet lain di browser.
  window.addEventListener("eip6963:announceProvider", (ev) => {
    const detail = ev.detail;
    if (!detail?.provider) return;
    const rdns = String(detail.info?.rdns || "").toLowerCase();
    const name = String(detail.info?.name || "").toLowerCase();
    if (rdns === "io.metamask" || rdns === "io.metamask.flask" || name.includes("metamask")) {
      announcedMetaMask = detail.provider;
    }
  });
  window.dispatchEvent(new Event("eip6963:requestProvider"));

  function getEthereum() {
    if (announcedMetaMask) return announcedMetaMask;
    const eth = window.ethereum;
    if (!eth) return null;
    if (Array.isArray(eth.providers) && eth.providers.length) {
      return eth.providers.find((p) => p.isMetaMask && !p.isBraveWallet && !p.isRabby)
        || eth.providers.find((p) => p.isMetaMask)
        || eth.providers[0];
    }
    return eth;
  }

  const say = (msg) => {
    const el = document.getElementById("flash");
    if (el) {
      el.textContent = msg;
      el.className = "flash ok";
      el.style.display = "";
    }
  };

  const alert2 = (msg) => {
    const el = document.getElementById("flash");
    if (el) {
      el.textContent = msg;
      el.className = "flash err";
      el.style.display = "";
    } else {
      alert(msg);
    }
  };

  function showModal(open) {
    const modal = document.getElementById("mm-guide");
    if (!modal) return;
    modal.style.display = open ? "flex" : "none";
    const rpcEl = modal.querySelector("[data-guide-rpc]");
    if (rpcEl) rpcEl.textContent = resolveRpcUrl();
  }

  // Buka seluruh lapisan error bersarang (termasuk saat Ethers membungkus "could not coalesce error").
  function extractRawError(e) {
    const candidates = [
      e?.data?.message,
      e?.error?.data?.message,
      e?.error?.message,
      e?.info?.error?.data?.message,
      e?.info?.error?.message,
      e?.reason,
      e?.shortMessage !== "could not coalesce error" ? e?.shortMessage : null,
      e?.message !== "could not coalesce error" ? e?.message : null,
    ];
    for (const c of candidates) {
      if (typeof c === "string" && c.trim() !== "" && !c.toLowerCase().includes("could not coalesce error")) {
        return c.trim();
      }
    }
    try {
      return JSON.stringify(e?.error || e?.info || e);
    } catch {
      return String(e || "Unknown error");
    }
  }

  function decodeError(e) {
    const code = e?.code ?? e?.error?.code ?? e?.info?.error?.code;
    const raw = extractRawError(e);
    const lower = raw.toLowerCase();

    if (code === 4001 || lower.includes("user rejected") || lower.includes("user denied") || lower.includes("action_rejected")) {
      return "Permintaan dibatalkan oleh pengguna di MetaMask.";
    }
    if (code === -32002 || lower.includes("already pending") || lower.includes("already processing")) {
      return "Popup MetaMask sedang menunggu persetujuan. Klik ikon rubah MetaMask di kanan atas browser lalu klik Confirm/Approve.";
    }
    if (lower.includes("circuit breaker") || lower.includes("too many errors")) {
      return "MetaMask sempat memblokir RPC lokal karena node Hardhat sebelumnya mati (circuit breaker). Buka MetaMask → pindah jaringan sebentar ke Ethereum Mainnet lalu balik ke SafePay Hardhat Local (atau klik Settings → Advanced → Clear activity tab data), lalu klik Bayar lagi.";
    }
    if (lower.includes("not buyer")) return "Ditolak Smart Contract: hanya pembeli pesanan ini yang boleh melakukan aksi tersebut.";
    if (lower.includes("not seller")) return "Ditolak Smart Contract: hanya penjual pesanan ini yang boleh menandai pengiriman.";
    if (lower.includes("not arbiter")) return "Ditolak Smart Contract: hanya validator/arbiter kontrak yang berhak memutus sengketa.";
    if (lower.includes("not party")) return "Ditolak Smart Contract: hanya pembeli atau penjual yang dapat mengajukan sengketa.";
    if (lower.includes("not refundable")) return "Ditolak Smart Contract: refund untuk status SHIPPED hanya bisa dilakukan setelah melewati deadline.";
    if (lower.includes("not shipped")) return "Ditolak Smart Contract: barang belum ditandai dikirim oleh penjual.";
    if (lower.includes("invalid recipient")) return "Ditolak Smart Contract: alamat pembeli dan penjual tidak boleh sama.";
    if (lower.includes("insufficient funds")) {
      return "Transaksi gagal: saldo ETH di akun MetaMask ini tidak mencukupi (klik tombol '+1 ETH Demo' untuk isi saldo simulasi).";
    }
    if (lower.includes("nonce too low") || lower.includes("nonce too high") || lower.includes("replacement fee") || lower.includes("invalid transaction nonce")) {
      return "Nonce MetaMask belum sinkron setelah restart Hardhat. Buka MetaMask → Settings → Advanced → Clear activity tab data, lalu klik Bayar lagi.";
    }
    if (lower.includes("https") && lower.includes("rpcurls")) {
      showModal(true);
      return "MetaMask memblokir penambahan RPC HTTP LAN otomatis. Tambahkan jaringan manual lewat panduan yang muncul (Chain ID 31337), lalu klik Connect MetaMask lagi.";
    }
    if (lower.includes("failed to fetch") || lower.includes("could not connect") || lower.includes("network error")) {
      showModal(true);
      return `MetaMask tidak dapat menjangkau node RPC (${resolveRpcUrl()}). Pastikan di MetaMask jaringan SafePay Hardhat Local memakai RPC URL ${resolveRpcUrl()}.`;
    }
    return raw;
  }

  async function loadInterface() {
    if (iface) return iface;
    const res = await fetch("/abi/SafePayEscrow.json?v=eth2");
    if (!res.ok) throw new Error("Gagal memuat ABI SafePayEscrow.");
    const abi = await res.json();
    iface = new ethers.Interface(abi);
    return iface;
  }

  function updateWalletUi(address, connected) {
    document.querySelectorAll("[data-wallet]").forEach((el) => {
      el.textContent = connected ? `wallet: ${shortAddr(address)}` : "wallet: belum tersambung";
      el.title = connected ? address : "";
    });
    const connectBtn = document.getElementById("connect");
    if (connectBtn) {
      connectBtn.textContent = connected ? "MetaMask Tersambung" : "Connect MetaMask";
    }
  }

  async function ensureHardhatNetwork(eth, interactive) {
    const targetHex = String(cfg.chainHex || "0x7a69").toLowerCase();
    const currentHex = String(await eth.request({ method: "eth_chainId" })).toLowerCase();
    if (currentHex === targetHex) return true;

    if (!interactive) return false;

    try {
      await eth.request({
        method: "wallet_switchEthereumChain",
        params: [{ chainId: targetHex }],
      });
    } catch (e) {
      const code = e?.code ?? e?.error?.code ?? e?.info?.error?.code;
      const msg = extractRawError(e).toLowerCase();
      const unknownChain = code === 4902 || msg.includes("4902") || msg.includes("unrecognized chain") || msg.includes("try adding the chain");

      if (!unknownChain) throw e;

      const rpcUrl = resolveRpcUrl();
      try {
        await eth.request({
          method: "wallet_addEthereumChain",
          params: [{
            chainId: targetHex,
            chainName: "SafePay Hardhat Local",
            nativeCurrency: { name: "Ether", symbol: "ETH", decimals: 18 },
            rpcUrls: [rpcUrl],
          }],
        });
      } catch (addErr) {
        showModal(true);
        throw addErr;
      }

      const afterAddHex = String(await eth.request({ method: "eth_chainId" })).toLowerCase();
      if (afterAddHex !== targetHex) {
        await eth.request({
          method: "wallet_switchEthereumChain",
          params: [{ chainId: targetHex }],
        });
      }
    }

    const finalHex = String(await eth.request({ method: "eth_chainId" })).toLowerCase();
    if (finalHex !== targetHex) {
      throw new Error(`Jaringan MetaMask masih di ${finalHex}, bukan SafePay Hardhat (${targetHex}).`);
    }
    return true;
  }

  async function bindWalletToServer(address) {
    if (!cfg.routes?.wallet) return { ok: true };
    const res = await fetch(cfg.routes.wallet, {
      method: "POST",
      headers: {
        "Content-Type": "application/json",
        "Accept": "application/json",
        "X-CSRF-TOKEN": csrf(),
      },
      body: JSON.stringify({ wallet_address: address }),
    });

    const data = await res.json().catch(() => ({}));
    if (!res.ok) {
      return { ok: false, message: data.message || `Gagal menyimpan wallet (HTTP ${res.status}).`, code: data.code };
    }
    cfg.userWallet = address;
    document.querySelectorAll("[data-registered-wallet]").forEach((el) => { el.textContent = address; });
    return { ok: true, data };
  }

  async function connect({ interactive = true, forcePermissions = false } = {}) {
    if (cfg.mode !== "wallet") return false;
    if (isConnecting) return false;

    const eth = getEthereum();
    if (!eth) {
      if (interactive) {
        showModal(true);
        alert2("MetaMask tidak terdeteksi di browser ini. Pastikan ekstensi MetaMask terpasang & aktif.");
      }
      return false;
    }

    if (typeof window.ethers === "undefined") {
      if (interactive) alert2("Library ethers.js gagal dimuat (/vendor/ethers.umd.min.js). Muat ulang halaman.");
      return false;
    }

    isConnecting = true;
    ethProvider = eth;

    try {
      if (interactive && forcePermissions) {
        // Buka ulang dialog izin akun & jaringan MetaMask v12+ (CAIP-25) agar Chain ID 31337 diotorisasi penuh.
        await eth.request({
          method: "wallet_requestPermissions",
          params: [{ eth_accounts: {} }],
        });
      }

      const accounts = await eth.request({
        method: interactive ? "eth_requestAccounts" : "eth_accounts",
      });

      if (!accounts || accounts.length === 0) {
        activeAccount = null;
        updateWalletUi(null, false);
        return false;
      }

      const onRightChain = await ensureHardhatNetwork(eth, interactive);
      if (!onRightChain) {
        activeAccount = null;
        updateWalletUi(accounts[0], false);
        if (interactive) alert2("Pindahkan jaringan MetaMask ke SafePay Hardhat Local (Chain ID 31337).");
        return false;
      }

      // Panggil eth_requestAccounts lagi SETELAH pindah chain supaya sesi di chain 31337 terotorisasi.
      const chainAccounts = interactive
        ? await eth.request({ method: "eth_requestAccounts" })
        : accounts;

      await loadInterface();
      const me = String(chainAccounts[0] || accounts[0]).toLowerCase();
      activeAccount = me;
      updateWalletUi(me, true);

      const bindResult = await bindWalletToServer(me);

      const escrowCode = await eth.request({
        method: "eth_getCode",
        params: [cfg.escrow, "latest"],
      });

      if (!escrowCode || escrowCode === "0x") {
        if (interactive) {
          alert2("Wallet tersambung, tetapi kontrak SafePayEscrow belum ter-deploy di node Hardhat saat ini. Jalankan 'npm run deploy' di folder blockchain lalu refresh.");
        }
        return false;
      }

      await balances();

      if (!bindResult.ok) {
        alert2(bindResult.message);
        return true;
      }

      if (interactive) {
        say(`MetaMask tersambung & diotorisasi: ${me}`);
      }
      return true;
    } finally {
      isConnecting = false;
    }
  }

  async function getBalanceWei(address) {
    const hex = await ethProvider.request({
      method: "eth_getBalance",
      params: [address, "latest"],
    });
    return BigInt(hex || "0x0");
  }

  async function balances() {
    if (!ethProvider || !activeAccount) return;
    try {
      const [mineWei, heldWei] = await Promise.all([
        getBalanceWei(activeAccount),
        cfg.escrow ? getBalanceWei(cfg.escrow) : Promise.resolve(0n),
      ]);

      document.querySelectorAll("[data-bal=mine]").forEach((el) => { el.textContent = fmtEth(mineWei); });
      document.querySelectorAll("[data-bal=mine-rp]").forEach((el) => { el.textContent = "≈ " + fmtRpFromWei(mineWei); });
      document.querySelectorAll("[data-bal=escrow]").forEach((el) => { el.textContent = fmtEth(heldWei); });
      document.querySelectorAll("[data-bal=escrow-rp]").forEach((el) => { el.textContent = "≈ " + fmtRpFromWei(heldWei); });
      document.querySelectorAll("[data-bal=eth]").forEach((el) => { el.textContent = fmtEth(mineWei); });
    } catch {
      // Abaikan gangguan sementara.
    }
  }

  function isUnauthorizedError(err) {
    const code = err?.code ?? err?.error?.code ?? err?.info?.error?.code;
    const msg = extractRawError(err).toLowerCase();
    return code === 4100 || msg.includes("not been authorized") || msg.includes("unauthorized");
  }

  // Minta izin ulang ke MetaMask jika akun atau jaringan Hardhat 31337 belum diotorisasi (error 4100).
  async function reauthorizeMetaMask() {
    await ethProvider.request({
      method: "wallet_requestPermissions",
      params: [{ eth_accounts: {} }],
    });
    await ensureHardhatNetwork(ethProvider, true);
    const fresh = await ethProvider.request({ method: "eth_requestAccounts" });
    if (!fresh || !fresh[0]) {
      throw new Error("Tidak ada akun MetaMask yang dipilih.");
    }
    activeAccount = String(fresh[0]).toLowerCase();
    updateWalletUi(activeAccount, true);
    return activeAccount;
  }

  // Kirim panggilan fungsi smart contract langsung lewat eth_sendTransaction MetaMask.
  async function sendContractTx(fnName, args, valueWei = 0n, onStatus = () => {}) {
    await loadInterface();

    // Pastikan akun aktif sudah diotorisasi MetaMask pada jaringan ini sebelum kirim tx.
    const authorizedAccs = await ethProvider.request({ method: "eth_requestAccounts" });
    if (authorizedAccs?.[0]) {
      activeAccount = String(authorizedAccs[0]).toLowerCase();
      updateWalletUi(activeAccount, true);
    }

    const data = iface.encodeFunctionData(fnName, args);
    const buildTx = () => {
      const tx = {
        from: activeAccount,
        to: String(cfg.escrow).toLowerCase(),
        data,
      };
      if (valueWei > 0n) {
        tx.value = toHexQty(valueWei);
      }
      return tx;
    };

    let baseTx = buildTx();

    // Estimasi gas lebih dulu agar error revert kontrak langsung terbaca jelas.
    let gasHex = null;
    try {
      const est = await ethProvider.request({
        method: "eth_estimateGas",
        params: [baseTx],
      });
      gasHex = toHexQty((BigInt(est) * 120n) / 100n);
    } catch (estErr) {
      if (isUnauthorizedError(estErr)) {
        await reauthorizeMetaMask();
        baseTx = buildTx();
        const est = await ethProvider.request({
          method: "eth_estimateGas",
          params: [baseTx],
        });
        gasHex = toHexQty((BigInt(est) * 120n) / 100n);
      } else {
        throw estErr;
      }
    }

    let txParams = Object.assign({}, baseTx, gasHex ? { gas: gasHex } : {});

    onStatus();
    let txHash;
    try {
      txHash = await ethProvider.request({
        method: "eth_sendTransaction",
        params: [txParams],
      });
    } catch (sendErr) {
      const rawMsg = extractRawError(sendErr).toLowerCase();
      const code = sendErr?.code ?? sendErr?.error?.code;

      if (code === 4001 || rawMsg.includes("user rejected") || rawMsg.includes("user denied")) {
        throw sendErr;
      }

      // Jika error 4100 ("The requested account and/or method has not been authorized"):
      // buka popup izin akun & jaringan MetaMask lalu kirim ulang otomatis!
      if (isUnauthorizedError(sendErr)) {
        await reauthorizeMetaMask();
        txParams = Object.assign({}, buildTx(), gasHex ? { gas: gasHex } : {});
        txHash = await ethProvider.request({
          method: "eth_sendTransaction",
          params: [txParams],
        });
      } else {
        // Fallback gasPrice legacy untuk jaringan lokal 31337.
        const gasPriceHex = await ethProvider.request({ method: "eth_gasPrice", params: [] }).catch(() => null);
        if (gasPriceHex) {
          txHash = await ethProvider.request({
            method: "eth_sendTransaction",
            params: [Object.assign({}, txParams, { gasPrice: gasPriceHex })],
          });
        } else {
          throw sendErr;
        }
      }
    }

    // Tunggu receipt transaksi sampai masuk blok Hardhat.
    for (let i = 0; i < 60; i++) {
      const receipt = await ethProvider.request({
        method: "eth_getTransactionReceipt",
        params: [txHash],
      });
      if (receipt) {
        if (receipt.status && receipt.status !== "0x1") {
          throw new Error("Transaksi gagal dieksekusi oleh Smart Contract (status 0x0).");
        }
        return txHash;
      }
      await new Promise((r) => setTimeout(r, 400));
    }

    return txHash;
  }

  async function requestEthFaucet(btn) {
    const old = btn.textContent;
    btn.disabled = true;
    btn.textContent = "Mengirim +1 ETH…";
    txInProgress = true;

    try {
      let targetAddr = activeAccount || cfg.userWallet || null;
      if (!targetAddr) {
        const eth = getEthereum();
        if (eth) {
          const accs = await eth.request({ method: "eth_accounts" }).catch(() => []);
          if (accs?.[0]) targetAddr = accs[0];
        }
      }

      const res = await fetch(cfg.routes.faucet, {
        method: "POST",
        headers: {
          "Content-Type": "application/json",
          "Accept": "application/json",
          "X-CSRF-TOKEN": csrf(),
        },
        body: JSON.stringify({ wallet_address: targetAddr }),
      });

      const data = await res.json().catch(() => ({}));
      if (!res.ok || !data.ok) {
        throw new Error(data.message || "Gagal mengisi ETH simulasi.");
      }

      await balances();
      say(data.message + " Saldo MetaMask langsung bertambah!");
      setTimeout(() => window.location.reload(), 900);
    } catch (e) {
      alert2(decodeError(e));
    } finally {
      txInProgress = false;
      btn.disabled = false;
      btn.textContent = old;
    }
  }

  function report(orderId, event, hash, extra) {
    const form = document.createElement("form");
    form.method = "POST";
    form.action = cfg.routes.tx.replace("__ID__", orderId);
    const fields = Object.assign({ _token: csrf(), tx_hash: hash, event_type: event }, extra || {});
    for (const [k, v] of Object.entries(fields)) {
      if (v === undefined || v === null) continue;
      const i = document.createElement("input");
      i.type = "hidden";
      i.name = k;
      i.value = v;
      form.appendChild(i);
    }
    document.body.appendChild(form);
    form.submit();
  }

  async function run(btn) {
    const label = btn.textContent;
    btn.disabled = true;
    txInProgress = true;

    try {
      if (!ethProvider || !activeAccount) {
        btn.textContent = "Menyambung MetaMask…";
        const ok = await connect({ interactive: true });
        if (!ok || !ethProvider || !activeAccount) {
          btn.disabled = false;
          btn.textContent = label;
          return;
        }
      }

      const orderId = btn.dataset.order;
      const id = Number(btn.dataset.contract);
      const amountRp = BigInt(btn.dataset.amount || 0);
      const weiAmount = amountRp * WEI_PER_IDR;
      const deadline = BigInt(btn.dataset.deadline || 0);
      const me = activeAccount;

      switch (btn.dataset.act) {
        case "pay": {
          const seller = btn.dataset.seller;
          if (!seller || !ethers.isAddress(seller)) {
            throw new Error("Alamat wallet penjual belum valid. Pastikan penjual sudah tersambung MetaMask.");
          }
          if (cfg.userWallet && me.toLowerCase() !== cfg.userWallet.toLowerCase()) {
            throw new Error(`Akun MetaMask aktif (${shortAddr(me)}) tidak sama dengan wallet akun ini (${shortAddr(cfg.userWallet)}). Pindahkan akun di MetaMask terlebih dahulu.`);
          }
          if (me.toLowerCase() === seller.toLowerCase()) {
            throw new Error("Ditolak Smart Contract: alamat pembeli dan penjual tidak boleh sama.");
          }

          const myWei = await getBalanceWei(me);
          if (myWei <= weiAmount) {
            throw new Error(
              `Saldo ETH tidak mencukupi: saldo kamu ${fmtEth(myWei)} (${fmtRpFromWei(myWei)}), dibutuhkan ${fmtEth(weiAmount)} (Rp ${Number(amountRp).toLocaleString("id-ID")}) + gas fee. Klik tombol '+1 ETH Demo' di kanan atas untuk isi saldo simulasi.`
            );
          }

          const latestBlock = await ethProvider.request({
            method: "eth_getBlockByNumber",
            params: ["latest", false],
          });
          const blockTs = BigInt(latestBlock?.timestamp || Math.floor(Date.now() / 1000));
          const effectiveDeadline = deadline > blockTs + 60n ? deadline : blockTs + 30n * 86400n;

          const hash = await sendContractTx(
            "createPayment",
            [ethers.getAddress(seller), effectiveDeadline],
            weiAmount,
            () => { btn.textContent = `Konfirmasi ${fmtEth(weiAmount)} di MetaMask…`; }
          );
          btn.textContent = "Mencatat bukti on-chain…";
          return report(orderId, "PaymentCreated", hash, { deadline: effectiveDeadline.toString() });
        }
        case "ship": {
          const hash = await sendContractTx(
            "markShipped",
            [id],
            0n,
            () => { btn.textContent = "Kirim status di MetaMask…"; }
          );
          return report(orderId, "Shipped", hash);
        }
        case "confirm": {
          const hash = await sendContractTx(
            "confirmReceived",
            [id],
            0n,
            () => { btn.textContent = "Melepas ETH escrow…"; }
          );
          return report(orderId, "Released", hash);
        }
        case "refund": {
          const hash = await sendContractTx(
            "refund",
            [id],
            0n,
            () => { btn.textContent = "Memproses refund ETH…"; }
          );
          return report(orderId, "Refunded", hash);
        }
        case "dispute": {
          const reason = prompt("Alasan sengketa:", btn.dataset.reason || "Barang tidak sesuai deskripsi");
          if (!reason || !reason.trim()) {
            btn.disabled = false;
            btn.textContent = label;
            return;
          }
          const hash = await sendContractTx(
            "raiseDispute",
            [id, reason.trim()],
            0n,
            () => { btn.textContent = "Mengajukan sengketa…"; }
          );
          return report(orderId, "Disputed", hash, { reason: reason.trim() });
        }
        case "resolve": {
          const toSeller = btn.dataset.toSeller === "1";
          const promptText = toSeller
            ? "Putuskan sengketa: lepaskan ETH escrow ke PENJUAL?"
            : "Putuskan sengketa: kembalikan (refund) ETH escrow ke PEMBELI?";
          if (!confirm(promptText)) {
            btn.disabled = false;
            btn.textContent = label;
            return;
          }
          const hash = await sendContractTx(
            "resolveDispute",
            [id, toSeller],
            0n,
            () => { btn.textContent = "Memutus sengketa…"; }
          );
          return report(orderId, "DisputeResolved", hash);
        }
      }
    } catch (e) {
      alert2(decodeError(e));
      btn.disabled = false;
      btn.textContent = label;
    } finally {
      txInProgress = false;
    }
  }

  function bindAmountPreview() {
    const input = document.querySelector('input[name="amount"]');
    const hint = document.getElementById("eth-preview");
    if (!input || !hint || typeof window.ethers === "undefined") return;
    const update = () => {
      const rp = BigInt(Math.max(0, Math.floor(Number(input.value || 0))));
      hint.textContent = `= ${fmtEth(rp * WEI_PER_IDR)}`;
    };
    input.addEventListener("input", update);
    update();
  }

  function startLiveSync() {
    if (!cfg.routes?.live) return;
    let lastHash = null;
    let lastTxId = null;
    const dot = document.getElementById("live-dot");

    function showToast(ev) {
      const box = document.getElementById("live-toast");
      if (!box || !ev) return;
      const label = {
        PaymentCreated: "ETH terkunci di escrow (FUNDED)",
        Shipped: "Penjual menandai barang dikirim (SHIPPED)",
        Released: "ETH dilepas ke penjual (RELEASED)",
        Refunded: "ETH dikembalikan ke pembeli (REFUNDED)",
        Disputed: "Sengketa diajukan (DISPUTED)",
        DisputeResolved: "Sengketa diputus validator",
      }[ev.event_type] || ev.event_type;

      const amt = ev.amount ? ` · Rp ${Number(ev.amount).toLocaleString("id-ID")}` : "";
      box.textContent = `🔔 Real-time [${ev.time || "baru saja"}] ${ev.product_name || "Escrow"}${amt} — ${label}`;
      box.style.display = "";
    }

    async function tick() {
      if (document.hidden || txInProgress || isConnecting) return;
      try {
        const res = await fetch(cfg.routes.live, { headers: { Accept: "application/json" } });
        if (!res.ok) return;
        const data = await res.json();

        // Ikuti alamat kontrak terbaru jika pengguna baru saja menjalankan npm run deploy.
        if (data.escrow && data.escrow !== cfg.escrow) {
          cfg.escrow = data.escrow;
        }

        if (dot) {
          dot.className = data.health?.node_ok ? "tag s3" : "tag s5";
          dot.textContent = data.health?.node_ok
            ? `● Live · Block #${data.health.block_number}`
            : "● Node Mati";
        }

        if (lastHash === null) {
          lastHash = data.state_hash;
          lastTxId = data.latest_tx_id;
          return;
        }

        if (data.state_hash !== lastHash) {
          const hasNewTx = data.latest_tx_id && data.latest_tx_id !== lastTxId;
          lastHash = data.state_hash;
          lastTxId = data.latest_tx_id;

          if (hasNewTx && data.latest_event) {
            showToast(data.latest_event);
          }

          const tag = document.activeElement?.tagName;
          const typing = tag === "INPUT" || tag === "TEXTAREA" || tag === "SELECT";
          if (!typing) {
            setTimeout(() => window.location.reload(), hasNewTx ? 1200 : 400);
          }
        }
      } catch {
        // Abaikan gangguan sementara.
      }
    }

    tick();
    setInterval(tick, 3000);
  }

  document.addEventListener("click", (ev) => {
    const btn = ev.target.closest("[data-act]");
    if (btn) {
      ev.preventDefault();
      run(btn);
      return;
    }
    if (ev.target.closest("#connect")) {
      ev.preventDefault();
      connect({ interactive: true, forcePermissions: true }).catch((e) => alert2(decodeError(e)));
      return;
    }
    const faucetBtn = ev.target.closest("#faucet-eth");
    if (faucetBtn) {
      ev.preventDefault();
      requestEthFaucet(faucetBtn);
      return;
    }
    if (ev.target.closest("#refresh-bal")) {
      ev.preventDefault();
      balances().then(() => say("Saldo ETH diperbarui dari blockchain."));
      return;
    }
    if (ev.target.closest("#open-mm-guide")) {
      ev.preventDefault();
      showModal(true);
      return;
    }
    if (ev.target.closest("#close-mm-guide") || ev.target.id === "mm-guide") {
      ev.preventDefault();
      showModal(false);
      return;
    }
    const copyBtn = ev.target.closest("[data-copy]");
    if (copyBtn) {
      ev.preventDefault();
      const target = copyBtn.dataset.copy === "rpc" ? resolveRpcUrl() : copyBtn.dataset.copy;
      navigator.clipboard?.writeText(target).then(() => say("Disalin: " + target));
    }
  });

  setTimeout(() => {
    bindAmountPreview();
    if (cfg.mode === "wallet") {
      const eth = getEthereum();
      if (eth?.on) {
        eth.on("accountsChanged", (accounts) => {
          if (isConnecting) return;
          if (!accounts || accounts.length === 0) {
            activeAccount = null;
            updateWalletUi(null, false);
          } else {
            connect({ interactive: false }).catch(() => {});
          }
        });
        eth.on("chainChanged", () => {
          if (isConnecting) return;
          connect({ interactive: false }).catch(() => {});
        });
      }
      connect({ interactive: false }).catch(() => {});
    }
    startLiveSync();
  }, 150);
})();
