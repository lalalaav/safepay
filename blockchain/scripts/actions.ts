// Satu pintu aksi on-chain dari backend Laravel untuk mode demo manual (tanpa MetaMask).
// Menggunakan ETH lokal Hardhat (1 ETH simulasi = Rp 1.000.000 -> 1 Rp = 1e12 wei).
import { network } from "hardhat";
import { readFile } from "node:fs/promises";

const WEI_PER_IDR = 1_000_000_000_000n;
const spec = JSON.parse(process.env.SAFEPAY_SPEC ?? "{}");
const { ethers } = await network.connect();

const d = JSON.parse(await readFile(new URL("../deployments.json", import.meta.url), "utf8"));
const signers = await ethers.getSigners();
const escrow = await ethers.getContractAt("SafePayEscrow", d.escrow);

function signerOf(address: string) {
  if (!address) throw new Error("alamat wallet kosong");
  const found = signers.find((s) => s.address.toLowerCase() === String(address).toLowerCase());
  if (!found) throw new Error("alamat " + address + " bukan akun Hardhat lokal");
  return found;
}

const deadline = BigInt(Math.floor(Date.now() / 1000) + Number(spec.deadline_seconds ?? 30 * 86400));

let id = Number(spec.contract_id ?? 0);
const out: Record<string, unknown> = { action: spec.action };

async function send(txPromise: Promise<{ hash: string; wait: () => Promise<unknown> }>) {
  const tx = await txPromise;
  await tx.wait();
  return tx.hash;
}

switch (spec.action) {
  case "pay": {
    const buyer = signerOf(spec.buyer);
    const seller = signerOf(spec.seller);
    const weiAmount = BigInt(spec.amount) * WEI_PER_IDR;
    out.tx_hash = await send(
      escrow.connect(buyer).createPayment(seller.address, deadline, { value: weiAmount }),
    );
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
      escrow.connect(signerOf(spec.actor ?? spec.buyer)).raiseDispute(id, spec.reason ?? "Barang tidak sesuai deskripsi"),
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

const payment = await escrow.payments(id);
out.status = payment.status.toString();
out.buyer_balance = (await ethers.provider.getBalance(payment.buyer)).toString();
out.seller_balance = (await ethers.provider.getBalance(payment.seller)).toString();
out.escrow_balance = (await ethers.provider.getBalance(d.escrow)).toString();

console.log(JSON.stringify(out));
