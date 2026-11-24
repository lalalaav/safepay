// Demo end-to-end alur dosen: buat pesanan -> kirim -> konfirmasi.
// Jalankan setelah `npm run deploy`, dengan node Hardhat hidup.
import { network } from "hardhat";
import { readFile } from "node:fs/promises";

const { ethers } = await network.connect();
const d = JSON.parse(await readFile(new URL("../deployments.json", import.meta.url), "utf8"));

const [andi, budi] = await ethers.getSigners(); // Andi pembeli, Budi penjual
const token = await ethers.getContractAt("MockIDR", d.token);
const escrow = await ethers.getContractAt("SafePayEscrow", d.escrow);

const DEADLINE = BigInt(Math.floor(Date.now() / 1000) + 30 * 86400);
const AMOUNT = 200_000n;

await (await token.connect(andi).approve(d.escrow, AMOUNT)).wait();

const tx = await escrow.connect(andi).createPayment(budi.address, AMOUNT, DEADLINE);
await tx.wait();
const id = Number(await escrow.nextId()) - 1;

console.log("id            =", id);
console.log("PaymentCreated=", tx.hash);
console.log("escrow balance=", (await token.balanceOf(d.escrow)).toString(), "(dana tertahan)");
console.log("seller balance=", (await token.balanceOf(budi.address)).toString(), "(belum dibayar)");

const ship = await escrow.connect(budi).markShipped(id);
await ship.wait();
console.log("Shipped       =", ship.hash);

const rel = await escrow.connect(andi).confirmReceived(id);
await rel.wait();
console.log("Released      =", rel.hash);
console.log("status akhir  =", (await escrow.payments(id)).status.toString(), "(3 = Released)");
console.log("seller balance=", (await token.balanceOf(budi.address)).toString());
console.log("escrow balance=", (await token.balanceOf(d.escrow)).toString());
