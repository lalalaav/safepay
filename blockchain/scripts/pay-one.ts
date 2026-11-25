// Jalur cadangan CLI: kunci dana ETH untuk pesanan tertentu dari CLI.
import { network } from "hardhat";
import { readFile, writeFile } from "node:fs/promises";

const WEI_PER_IDR = 1_000_000_000_000n;
const { ethers } = await network.connect();
const d = JSON.parse(await readFile(new URL("../deployments.json", import.meta.url), "utf8"));

const amountIdr = BigInt(process.env.AMOUNT ?? "200000");
const amountWei = amountIdr * WEI_PER_IDR;
const [buyer, seller] = await ethers.getSigners();
const escrow = await ethers.getContractAt("SafePayEscrow", d.escrow);

const deadline = BigInt(Math.floor(Date.now() / 1000) + 30 * 86400);
const tx = await escrow.connect(buyer).createPayment(seller.address, deadline, { value: amountWei });
await tx.wait();

const result = {
  tx_hash: tx.hash,
  contract_id: Number(await escrow.nextId()) - 1,
  deadline: deadline.toString(),
  seller: seller.address,
  amount: amountIdr.toString(),
  amount_eth: ethers.formatEther(amountWei),
};

console.log(JSON.stringify(result));

if (process.env.OUT) {
  await writeFile(process.env.OUT, JSON.stringify(result));
}
