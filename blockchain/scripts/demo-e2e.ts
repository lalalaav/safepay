// Demo end-to-end alur dosen dengan ETH lokal: buat pesanan -> kirim -> konfirmasi.
import { network } from "hardhat";
import { readFile } from "node:fs/promises";

const WEI_PER_IDR = 1_000_000_000_000n;
const { ethers } = await network.connect();
const d = JSON.parse(await readFile(new URL("../deployments.json", import.meta.url), "utf8"));

const [andi, budi] = await ethers.getSigners();
const escrow = await ethers.getContractAt("SafePayEscrow", d.escrow);

const DEADLINE = BigInt(Math.floor(Date.now() / 1000) + 30 * 86400);
const AMOUNT_WEI = 200_000n * WEI_PER_IDR; // Rp 200.000 = 0.2 ETH

const tx = await escrow.connect(andi).createPayment(budi.address, DEADLINE, { value: AMOUNT_WEI });
await tx.wait();
const id = Number(await escrow.nextId()) - 1;

console.log("id            =", id);
console.log("PaymentCreated=", tx.hash);
console.log("escrow ETH    =", ethers.formatEther(await ethers.provider.getBalance(d.escrow)), "ETH (dana tertahan)");

const ship = await escrow.connect(budi).markShipped(id);
await ship.wait();
console.log("Shipped       =", ship.hash);

const rel = await escrow.connect(andi).confirmReceived(id);
await rel.wait();
console.log("Released      =", rel.hash);
console.log("status akhir  =", (await escrow.payments(id)).status.toString(), "(3 = Released)");
console.log("escrow ETH    =", ethers.formatEther(await ethers.provider.getBalance(d.escrow)), "ETH");
