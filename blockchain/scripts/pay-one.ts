// Jalur cadangan demo tanpa MetaMask: kunci dana untuk pesanan tertentu dari CLI.
// Pakai kalau browser/MetaMask bermasalah saat presentasi.
//   AMOUNT=150000 OUT=out.json npm run pay-one
import { network } from "hardhat";
import { readFile, writeFile } from "node:fs/promises";

const { ethers } = await network.connect();
const d = JSON.parse(await readFile(new URL("../deployments.json", import.meta.url), "utf8"));

const amount = BigInt(process.env.AMOUNT ?? "200000");
const [buyer, seller] = await ethers.getSigners();
const token = await ethers.getContractAt("MockIDR", d.token);
const escrow = await ethers.getContractAt("SafePayEscrow", d.escrow);

await (await token.connect(buyer).approve(d.escrow, amount)).wait();
const deadline = BigInt(Math.floor(Date.now() / 1000) + 30 * 86400);
const tx = await escrow.connect(buyer).createPayment(seller.address, amount, deadline);
await tx.wait();

const result = {
  tx_hash: tx.hash,
  contract_id: Number(await escrow.nextId()) - 1,
  deadline: deadline.toString(),
  seller: seller.address,
  amount: amount.toString(),
};

console.log(JSON.stringify(result));

if (process.env.OUT) {
  await writeFile(process.env.OUT, JSON.stringify(result));
}
