/* Satu-satunya jalur tulis ke blockchain: MetaMask tanda tangan di browser,
   hash-nya dikirim ke Laravel untuk diverifikasi. Server tidak pernah pegang private key. */
(function () {
  const cfg = window.SAFEPAY;
  let provider, signer, escrow, token, abi = {};

  const csrf = () => document.querySelector('meta[name=csrf-token]').content;
  const addr = (a) => a.slice(0, 10) + "…" + a.slice(-6);
  const say = (msg) => {
    const el = document.getElementById("flash");
    // #flash dirender dengan display:none supaya tidak tampil kosong; buka saat dipakai.
    if (el) { el.textContent = msg; el.className = "flash ok"; el.style.display = ""; }
  };

  const alert2 = (msg) => {
    const el = document.getElementById("flash");
    if (el) { el.textContent = msg; el.className = "flash err"; el.style.display = ""; }
    else alert(msg);
  };

  async function loadAbi() {
    if (abi.escrow) return abi;
    const [e, t] = await Promise.all([
      fetch("/abi/SafePayEscrow.json").then((r) => r.json()),
      fetch("/abi/MockIDR.json").then((r) => r.json()),
    ]);
    abi = { escrow: e, token: t };
    return abi;
  }

  async function connect() {
    if (!window.ethereum) return alert2("MetaMask tidak ada. Pasang ekstensi MetaMask dulu di browser ini.");
    provider = new ethers.BrowserProvider(window.ethereum);
    await provider.send("eth_requestAccounts", []);

    const hex = cfg.chainHex;
    try {
      await provider.send("wallet_switchEthereumChain", [{ chainId: hex }]);
    } catch (e) {
      // 4902 = jaringan belum dikenal MetaMask -> daftarkan jaringan Hardhat server.
      if (e.code === 4902) {
        await provider.send("wallet_addEthereumChain", [{
          chainId: hex,
          chainName: "SafePay Hardhat Local",
          nativeCurrency: { name: "Ether", symbol: "ETH", decimals: 18 },
          rpcUrls: [cfg.rpc],
        }]);
      } else { throw e; }
    }

    signer = await provider.getSigner();
    await loadAbi();
    escrow = new ethers.Contract(cfg.escrow, abi.escrow, signer);
    token = new ethers.Contract(cfg.token, abi.token, signer);

    const me = await signer.getAddress();
    document.querySelectorAll("[data-wallet]").forEach((el) => { el.textContent = addr(me); });

    await fetch(cfg.routes.wallet, {
      method: "POST",
      headers: { "Content-Type": "application/json", "X-CSRF-TOKEN": csrf() },
      body: JSON.stringify({ wallet_address: me }),
    });

    await balances();
    say("Wallet tersambung: " + me);
  }

  async function balances() {
    if (!token || !signer) return;
    const me = await signer.getAddress();
    const [mine, held] = await Promise.all([token.balanceOf(me), token.balanceOf(cfg.escrow)]);
    const fmt = (v) => "Rp " + Number(v).toLocaleString("id-ID");
    document.querySelectorAll("[data-bal=mine]").forEach((el) => { el.textContent = fmt(mine); });
    document.querySelectorAll("[data-bal=escrow]").forEach((el) => { el.textContent = fmt(held); });
  }

  // Kirim hash ke Laravel lewat form asli supaya pesan hasil verifikasi tampil sebagai flash.
  function report(orderId, event, hash, extra) {
    const form = document.createElement("form");
    form.method = "POST";
    form.action = cfg.routes.tx.replace("__ID__", orderId);
    const fields = Object.assign({ _token: csrf(), tx_hash: hash, event_type: event }, extra || {});
    for (const [k, v] of Object.entries(fields)) {
      const i = document.createElement("input");
      i.type = "hidden"; i.name = k; i.value = v;
      form.appendChild(i);
    }
    document.body.appendChild(form);
    form.submit();
  }

  async function run(btn) {
    if (!signer) await connect();
    if (!signer) return;
    const label = btn.textContent;
    btn.disabled = true;
    btn.textContent = "Cek MetaMask…";

    const orderId = btn.dataset.order;
    const id = Number(btn.dataset.contract);
    const amount = BigInt(btn.dataset.amount || 0);
    const deadline = BigInt(btn.dataset.deadline || 0);

    try {
      switch (btn.dataset.act) {
        case "pay": {
          // approve wajib lebih dulu: kontrak menarik dana via transferFrom.
          btn.textContent = "Approve mIDR…";
          await (await token.approve(cfg.escrow, amount)).wait();
          btn.textContent = "Kunci dana di escrow…";
          const tx = await escrow.createPayment(btn.dataset.seller, amount, deadline);
          await tx.wait();
          return report(orderId, "PaymentCreated", tx.hash, { contract_id: id, deadline: deadline.toString() });
        }
        case "ship": {
          const tx = await escrow.markShipped(id); await tx.wait();
          return report(orderId, "Shipped", tx.hash);
        }
        case "confirm": {
          const tx = await escrow.confirmReceived(id); await tx.wait();
          return report(orderId, "Released", tx.hash);
        }
        case "refund": {
          const tx = await escrow.refund(id); await tx.wait();
          return report(orderId, "Refunded", tx.hash);
        }
        case "dispute": {
          const reason = prompt("Alasan sengketa:", "Barang tidak sesuai deskripsi");
          if (!reason) throw new Error("dibatalkan");
          const tx = await escrow.raiseDispute(id, reason); await tx.wait();
          return report(orderId, "Disputed", tx.hash);
        }
        case "resolve": {
          const toSeller = confirm("OK = dana ke PENJUAL\nCancel = dana kembali ke PEMBELI");
          const tx = await escrow.resolveDispute(id, toSeller); await tx.wait();
          return report(orderId, "DisputeResolved", tx.hash);
        }
      }
    } catch (e) {
      alert2(e.shortMessage || e.reason || e.message || String(e));
      btn.disabled = false;
      btn.textContent = label;
    }
  }

  document.addEventListener("click", (ev) => {
    const btn = ev.target.closest("[data-act]");
    if (btn) { ev.preventDefault(); run(btn); }
    if (ev.target.closest("#connect")) { ev.preventDefault(); connect().catch((e) => alert2(e.message)); }
    if (ev.target.id === "refresh-bal") { ev.preventDefault(); balances(); }
  });

  // MetaMask ganti akun/jaringan di tengah demo: sambung ulang otomatis.
  window.ethereum?.on?.("accountsChanged", () => connect().catch(() => {}));
  window.ethereum?.on?.("chainChanged", () => connect().catch(() => {}));

  if (window.ethereum) connect().catch(() => {});
})();
