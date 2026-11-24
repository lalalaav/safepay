// Satu pintu aksi on-chain dari backend Laravel, dipakai mode demo manual
// (tanpa MetaMask). Laravel mengirim nama aksi + alamat pelaku lewat env
// SAFEPAY_SPEC; script ini memilih akun Hardhat yang cocok dengan alamat itu,
// menjalankan transaksi, lalu mencetak SATU baris JSON hasilnya.
//
// Akun yang dipakai BUKAN "akun 0 selalu" — pelakunya ditentukan alamat wallet
// yang tersimpan di tabel wallets, jadi demo tetap sesuai peran: Andi bayar,
// Budi kirim, Citra memutus sengketa.
import { network } from "hardhat";
import { readFile } from "node:fs/promises";

const spec = JSON.parse(process.env.SAFEPAY_SPEC ?? "{}");
const { ethers } = await network.connect();

const d = JSON.parse(await readFile(new URL("../deployments.json", import.meta.url), "utf8"));
const signers = await ethers.getSigners();
const token = await ethers.getContractAt("MockIDR", d.token);
const escrow = await ethers.getContractAt("SafePayEscrow", d.escrow);

// Alamat yang tidak ada di daftar akun Hardhat berarti bukan peserta demo.
function signerOf(address) {
  if (!address) throw new Error("alamat wallet kosong");
  const found = signers.find((s) => s.address.toLowerCase() === String(address).toLowerCase());
  if (!found) throw new Error("alamat " + address + " bukan akun Hardhat lokal");
  return found;
}

// 30 hari default. Demo refund cepat bisa mengirim deadline_seconds kecil.
const deadline = BigInt(Math.floor(Date.now() / 1000) + Number(spec.deadline_seconds ?? 30 * 86400));

let id = Number(spec.contract_id ?? 0);
const out = { action: spec.action };

async function send(txPromise) {
  const tx = await txPromise;
  await tx.wait();
  return tx.hash;
}

switch (spec.action) {
  case "pay": {
    const buyer = signerOf(spec.buyer);
    const seller = signerOf(spec.seller);
    const amount = BigInt(spec.amount);
    // approve wajib lebih dulu: kontrak menarik dana via transferFrom.
    await (await token.connect(buyer).approve(d.escrow, amount)).wait();
    out.tx_hash = await send(escrow.connect(buyer).createPayment(seller.address, amount, deadline));
    id = Number(await escrow.nextId()) - 1;
    out.contract_id = id;
    out.deadline = deadline.toString();
    break;
  }
  case "ship":
    out.tx_hash = await send(escrow.connect(signerOf(spec.seller)).markShipped(id));
    break;
  case "confirm":
    out.tx_hash = await send(escrow.connect(signerOf(spec.buyer)).confirmReceived(id));
    break;
  case "refund":
    out.tx_hash = await send(escrow.connect(signerOf(spec.buyer)).refund(id));
    break;
  case "dispute":
    out.tx_hash = await send(
      escrow.connect(signerOf(spec.buyer)).raiseDispute(id, spec.reason ?? "Barang tidak sesuai deskripsi"),
    );
    break;
  case "resolve":
    out.tx_hash = await send(
      escrow.connect(signerOf(spec.arbiter)).resolveDispute(id, Boolean(spec.to_seller)),
    );
    break;
  default:
    throw new Error("aksi tidak dikenal: " + spec.action);
}

// Status akhir dibaca dari kontrak, bukan dari asumsi PHP.
const payment = await escrow.payments(id);
out.status = payment.status.toString();
out.buyer_balance = (await token.balanceOf(payment.buyer)).toString();
out.seller_balance = (await token.balanceOf(payment.seller)).toString();
out.escrow_balance = (await token.balanceOf(d.escrow)).toString();

console.log(JSON.stringify(out));
